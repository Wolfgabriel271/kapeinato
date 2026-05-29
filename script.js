/**
 * This file contains the client-side QR generation and QR scanning logic for the KAPE Inato workflow. It connects the browser UI to third-party QR libraries and to the `qr-tool.php` backend endpoint for persistence.
 * Technically, the script manages DOM interactions, asynchronous network requests, and scanner lifecycle state so the page can generate, download, scan, and save QR-based order data without a full page transition.
 */

let html5QrcodeScanner = null;
let isScanning = false;
let scanHandled = false;

/**
 * These state variables keep track of the scanner instance and prevent duplicate processing while the camera is active. They are important because QR scanners can emit repeated detections across consecutive frames, which would otherwise trigger repeated UI updates and duplicate backend requests.
 */

/**
 * This section is responsible for instant QR code generation based on user input from the page. It validates the input, normalizes problematic characters, and renders a new QR image into the target container using the QRCode library.
 */
function generateQR() {
    let inputText = document.getElementById('qr-input').value.trim();
    const qrContainer = document.getElementById('qrcode');
    const size = parseInt(document.getElementById('qr-size').value) || 200;
    const downloadBtn = document.getElementById('download-btn');
    const qrLabel = document.getElementById('qr-label');

    if (!inputText) {
        alert("⚠️ Please enter table / order details first.");
        return;
    }

    /**
     * This normalization step replaces typographic dash characters with the standard ASCII hyphen. Technically, it reduces encoding inconsistencies that can appear when text is copied from formatted sources and helps ensure the generated QR payload stays predictable across scanners and backend parsing.
     */
    inputText = inputText.replace(/—/g, "-").replace(/–/g, "-");

    qrContainer.innerHTML = "";
    qrContainer.style.display = "none";

    try {
        new QRCode(qrContainer, {
            text: inputText,
            width: size,
            height: size,
            colorDark: "#000000",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });

        qrContainer.style.display = 'inline-block';
        downloadBtn.style.display = 'block';
        qrLabel.style.display = 'block';
        qrLabel.textContent = `QR generated for: "${inputText}"`;

    } catch (error) {
        console.error("QR Generation Failed:", error);
        alert("Error generating QR code. Check console.");
    }
}

/**
 * This helper clears the rendered QR code and resets the related UI elements back to their hidden state. It is intentionally lightweight because it only performs DOM cleanup and does not need to communicate with the server or reinitialize any external library.
 */
function clearQR() {
    const qrContainer = document.getElementById('qrcode');
    qrContainer.innerHTML = "";
    qrContainer.style.display = 'none';
    document.getElementById('download-btn').style.display = 'none';
    document.getElementById('qr-label').style.display = 'none';
}

/**
 * This section handles both the browser download flow and the server-side save flow for generated QR codes. From a technical perspective, it first triggers a local download using a temporary anchor element and then sends the same QR image as base64 data to the backend through `fetch` and `FormData`.
 */
function downloadQR() {
    const img = document.querySelector('#qrcode img');
    if (!img) {
        alert('Generate a QR code first.');
        return;
    }

    const a = document.createElement('a');
    a.href = img.src;
    a.download = 'kapeinato-order-qr.png';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);

    const formData = new FormData();
    formData.append('qr_base64', img.src);

    fetch('qr-tool.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                alert('✅ QR Code permanently saved to uploads/ folder.');
            }
        })
        .catch(err => console.error("AJAX Error:", err));
}

/**
 * This section initializes the scanner-related event listeners only after the DOM has fully loaded. That timing matters technically because the buttons must already exist in the document before the script attaches click handlers to them.
 */
document.addEventListener('DOMContentLoaded', function () {
    const startBtn = document.getElementById('start-scan-btn');
    const clearBtn = document.getElementById('clear-scan-btn');

    if (startBtn) {
        startBtn.addEventListener('click', startScanner);
    }
    if (clearBtn) {
        clearBtn.addEventListener('click', clearScan);
    }
});

/**
 * This function starts the QR scanner and configures the camera-based reading experience for the page. Technically, it instantiates `Html5QrcodeScanner` with performance-oriented settings, renders the scanning UI into the `reader` container, and updates internal state so the scanner is not started twice.
 */
function startScanner() {
    if (isScanning) return;

    const startBtn = document.getElementById('start-scan-btn');
    if (startBtn) startBtn.style.display = 'none';

    scanHandled = false;

    /**
     * This configuration block defines how the third-party scanner should interact with the camera and process frames. The selected options prioritize full-view scanning, stable orientation, camera memory, and native detection support so QR recognition feels smoother and more reliable on supported devices.
     */
    html5QrcodeScanner = new Html5QrcodeScanner(
        "reader",
        {
            fps: 60,
            disableFlip: true,
            aspectRatio: 1.333334,
            rememberLastUsedCamera: true,
            showTorchButtonIfSupported: false,
            experimentalFeatures: {
                useBarCodeDetectorIfSupported: true
            },
            formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE]
        },
        /* verbose= */ false
    );

    html5QrcodeScanner.render(onScanSuccess, onScanFailure);
    isScanning = true;
    showScanStatus('scanning', '🔍 60 FPS Omni-Scanner active — wave QR code anywhere in view!');
}

/**
 * This callback runs when the scanner successfully decodes a QR code from the camera feed. It prevents duplicate handling, shuts down the active scanner instance, updates the user-facing status panel, and posts the decoded value to the backend so the scanned order can be stored.
 */
function onScanSuccess(decodedText) {
    if (scanHandled) return;
    scanHandled = true;
    isScanning = false;

    /**
     * This cleanup step attempts to stop and clear the scanner UI without throwing an uncaught error into the page. Technically, calling `clear()` releases the active scanner resources and helps avoid camera lock issues or duplicate scanner overlays after a successful read.
     */
    if (html5QrcodeScanner) {
        html5QrcodeScanner.clear().catch(() => {});
    }

    const resultEl = document.getElementById('scan-result');
    const clearBtn = document.getElementById('clear-scan-btn');

    resultEl.innerHTML = `
        <div style="background:rgba(245,158,11,0.1); padding:15px; border-radius:8px; border:1px solid #f59e0b; color:#f59e0b;">
            <strong>⏳ Order Scanned! Saving to Database...</strong><br>${decodedText}
        </div>`;
    resultEl.style.display = 'block';

    const formData = new FormData();
    formData.append('scanned_text', decodedText);

    fetch('qr-tool.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                resultEl.innerHTML = `
                    <div style="background:rgba(34,197,94,0.1); padding:15px; border-radius:8px; border:1px solid #22c55e; color:#22c55e;">
                        <strong>✅ Order Scanned & Saved to Database!</strong><br>${decodedText}
                    </div>`;
            } else {
                resultEl.innerHTML = `
                    <div style="background:rgba(192,57,43,0.1); padding:15px; border-radius:8px; border:1px solid #e87c70; color:#e87c70;">
                        <strong>⚠️ Scanned, but DB Error:</strong><br>${data.message}
                    </div>`;
            }
            if (clearBtn) clearBtn.style.display = 'block';
        })
        .catch(err => {
            console.error("Database Save Error:", err);
            resultEl.innerHTML = `
                <div style="background:rgba(192,57,43,0.1); padding:15px; border-radius:8px; border:1px solid #e87c70; color:#e87c70;">
                    <strong>⚠️ Network error saving order.</strong><br>${decodedText}
                </div>`;
            if (clearBtn) clearBtn.style.display = 'block';
        });
}

/**
 * This callback is invoked when a scan attempt fails on a given frame or when no readable QR code is found. The function stays intentionally empty because frequent scan misses are normal in continuous camera processing, and surfacing every failure would create unnecessary noise in the interface and console.
 */
function onScanFailure(errorMessage) {
}

/**
 * This utility renders a styled status message inside the scan result container based on the current scanner state. Technically, it centralizes the visual feedback rules by mapping semantic states such as `scanning`, `error`, and `success` into reusable color tokens and HTML output.
 */
function showScanStatus(type, message) {
    const resultEl = document.getElementById('scan-result');
    if (!resultEl) return;
    const colors = {
        scanning: { bg: 'rgba(245,158,11,0.08)', border: '#f59e0b', text: '#f59e0b' },
        error:    { bg: 'rgba(192,57,43,0.1)',   border: '#e87c70', text: '#e87c70' },
        success:  { bg: 'rgba(34,197,94,0.1)',   border: '#22c55e', text: '#22c55e' }
    };
    const c = colors[type] || colors.scanning;
    resultEl.innerHTML = `
        <div style="background:${c.bg}; padding:12px; border-radius:8px; border:1px solid ${c.border}; color:${c.text}; font-size:0.88rem;">
            ${message}
        </div>`;
    resultEl.style.display = 'block';
}

/**
 * This function resets the scan workflow by reloading the current page. From a technical standpoint, a full reload is the simplest way to restore DOM state, scanner state, and button visibility in one operation without manually reversing every previous UI and library side effect.
 */
function clearScan() {
    location.reload();
}