<!-- Description: Main landing page for Kape Inato cafe website.
Function: Displays homepage with navigation, hero section, features, and footer.
Technical: HTML5 structure with CSS styling, JavaScript for live clock, responsive design. -->
<!DOCTYPE html>
<html lang="en">
<!-- Description: HTML document head section containing metadata and styles.
Function: Sets page title, charset, viewport, favicon, and links to external stylesheets.
Technical: Uses meta tags for SEO and mobile responsiveness, links to style.css for styling. -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kape Inato | Premium Cafe — Tagbilaran, Bohol</title>
    
    <!-- FAVICON[cite: 25] -->
    <!-- Description: Sets the browser tab icon.
    Function: Displays coffee.png as the favicon in browser tabs.
    Technical: Uses link rel="icon" with PNG format for cross-browser compatibility. -->
    <link rel="icon" type="image/png" href="coffee.png">
    
    <!-- Description: Links to the main stylesheet.
    Function: Applies custom CSS styles to the page.
    Technical: External stylesheet link for maintainable styling. -->
    <link rel="stylesheet" href="style.css">
    <!-- Description: Inline CSS for social media hover effects.
    Function: Adds interactive styling to social media links.
    Technical: CSS transitions for smooth animations on hover. -->
    <style>
        /* Social Media Hover Effect */
        .social-link img {
            width: 35px;
            height: 35px;
            transition: transform 0.3s ease, filter 0.3s ease;
            filter: grayscale(1) brightness(1.5);
        }
        .social-link:hover img {
            transform: scale(1.15);
            filter: grayscale(0) brightness(1);
        }
    </style>
</head>
<!-- Description: HTML body containing the page content.
Function: Structures the visible page layout with navigation, sections, and footer.
Technical: Semantic HTML elements for accessibility and SEO. -->
<body>

<!-- ===== NAVBAR[cite: 25] ===== -->
<!-- Description: Navigation bar with logo, live clock, and menu links.
Function: Provides site navigation and displays current time/date.
Technical: Flexbox layout, links to different pages, JavaScript-powered clock. -->
<nav>
    <div class="nav-logo">Kape Inato</div>
    <div style="display:flex; align-items:center; gap:20px;">
        <!-- Description: Live clock display element.
        Function: Shows current time and date, updated every second.
        Technical: JavaScript manipulates innerHTML, uses monospace font for readability. -->
        <div id="liveClock" style="font-family:'Courier New',monospace; font-size:0.9rem; color:var(--amber); background:rgba(0,0,0,0.3); padding:6px 12px; border-radius:8px; border:1px solid var(--border-subtle); text-align:center;">
            🕐 --:--:--
        </div>
        <!-- Description: Navigation menu links.
        Function: Allows users to navigate to different sections of the site.
        Technical: Unordered list with anchor tags, highlights current page. -->
        <ul>
            <li><a href="index.php" style="color:var(--amber);">Home</a></li>
            <li><a href="menu.php">Menu</a></li>
            <li><a href="order.php">Order Online 🌐</a></li>
            <li><a href="login.php" class="nav-btn-admin">Admin</a></li>
        </ul>
    </div>
</nav>

<!-- Description: JavaScript for live clock functionality.
Function: Updates the clock display every second with current time and date.
Technical: Uses setInterval for periodic updates, Date object for time formatting. -->
<script>
// Live Clock Script[cite: 25]
function updateClock() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const dateString = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
    document.getElementById('liveClock').innerHTML = '🕐 ' + timeString + '<br><small>' + dateString + '</small>';
}
setInterval(updateClock, 1000);
updateClock();
</script>

<!-- ===== HERO SECTION[cite: 25] ===== -->
<!-- Description: Main hero/banner section with cafe introduction.
Function: Welcomes visitors and provides call-to-action buttons.
Technical: Background image, centered content, responsive design. -->
<section class="hero">
    <div class="hero-bg"></div>
    <div class="hero-content">
        <p class="hero-eyebrow">Panda Tea · J.A. Clarins St · Dao, Tagbilaran</p>
        <h1>Made with<br><em>Heart</em></h1>
        <p class="hero-desc">Your local Kapehan. Handcrafted family recipes, premium cold brews, and homemade pizzas made daily from scratch in Bohol.</p>
        <div class="hero-cta">
            <a href="menu.php" class="btn btn-primary">Explore Menu &rarr;</a>
            <a href="order.php" class="btn btn-ghost">Order Online 🌐</a>
        </div>
    </div>
    <div class="hero-scroll">Scroll</div>
</section>

<hr class="divider">

<!-- ===== FEATURES SECTION[cite: 25] ===== -->
<!-- Description: Features section highlighting cafe's unique selling points.
Function: Showcases what makes the cafe special with icons and descriptions.
Technical: Grid layout, feature cards with icons and text. -->
<section class="section features-section">
    <div class="section-inner">
        <div class="section-header" style="text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%;">
            <span class="section-eyebrow">Why Inato?</span>
            <h2>The <span>Inato</span> Experience</h2>
            <p style="text-align: center; max-width: 650px; margin: 0 auto;">We're not another generic cafe. Every item on our menu carries a story rooted in family tradition and local craftsmanship.</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <span class="feature-icon">🍕</span>
                <h3>Family Recipes</h3>
                <p>Our story is rooted in a family love for cooking. We didn't copy menus — we created our own hand-crafted pizzas and pastas from scratch so you eat better.</p>
            </div>
            <div class="feature-card">
                <span class="feature-icon">☕</span>
                <h3>Craft Coffee</h3>
                <p>Tired of overpriced, cold coffee? So were we. Experience our signature hot and cold brews, made perfectly to match Bohol's warm island aesthetic.</p>
            </div>
            <div class="feature-card">
                <span class="feature-icon">🧺</span>
                <h3>Wash &amp; Chill</h3>
                <p>A dual-business experience. Drop off your laundry, then relax in our cafe with a perfectly brewed drink while you wait in comfort.</p>
            </div>
            <div class="feature-card">
                <span class="feature-icon">🌐</span>
                <h3>Order Online</h3>
                <p>Can't make it in? Order ahead from our full menu online. Pick up your favorites fresh and ready — no waiting, no fuss.</p>
            </div>
        </div>
    </div>
</section>

<hr class="divider">

<!-- ===== OUR STORY SECTION ===== -->
<section class="section story-section">
    <div class="section-inner">
        <div class="section-header" style="text-align:center; display:flex; flex-direction:column; align-items:center; justify-content:center; width:100%;">
            <span class="section-eyebrow">Our Story</span>
            <h2>Born from a <span>Family</span> Table</h2>
            <p style="text-align:center; max-width:680px; margin:0 auto;">
                Kape En Ato didn't start in a commercial kitchen it started at home, with a mother who believed the best food comes from love and a son who turned that belief into a business.
            </p>
        </div>

        <div class="story-grid">

            <!-- Timeline Item 1 -->
            <div class="story-card">
                <div class="story-year">Origin</div>
                <div class="story-icon">🏠</div>
                <h3>A Family Love for Cooking</h3>
                <p>
                    Aniqa Napisa and her family grew tired of overpriced, mediocre food especially cold, forgettable coffee. Her mother encouraged cooking at home instead, and that spark of frustration became the foundation of Kape En Ato: a place built on original recipes crafted by family, for everyone.
                </p>
            </div>

            <!-- Timeline Item 2 -->
            <div class="story-card">
                <div class="story-year">2 Years Ago</div>
                <div class="story-icon">🌱</div>
                <h3>Launched During the Pandemic</h3>
                <p>
                    The café opened its doors during one of the hardest times in recent history. After an initial launch, restrictions and customer hesitancy led to a temporary closure but the passion never stopped. Kape En Ato came back stronger, more focused, and more committed than ever.
                </p>
            </div>

            <!-- Timeline Item 3 -->
            <div class="story-card">
                <div class="story-year">Today</div>
                <div class="story-icon">☕</div>
                <h3>Handmade, Every Single Day</h3>
                <p>
                    Every pizza, pasta, and appetizer is made fresh by hand daily no shortcuts, no copied recipes. Aniqa and her family continue to lead from the kitchen, ensuring each dish carries the same care and authenticity that started it all in Tagbilaran, Bohol.
                </p>
            </div>

            <!-- Timeline Item 4 -->
            <div class="story-card">
                <div class="story-year">Innovation</div>
                <div class="story-icon">🧺</div>
                <h3>The Café + Laundry Concept</h3>
                <p>
                    Why wait for laundry alone? Aniqa combined a full laundry service with the café experience separated thoughtfully so neither disturbs the other. Drop off your clothes, sit down with a cold brew, and leave with both your laundry done and your appetite satisfied.
                </p>
            </div>

        </div>

        <!-- Owner Quote -->
        <div class="story-quote">
            <blockquote>
                "We didn't copy menus we created our own. Everything you eat here carries a piece of our family in it."
            </blockquote>
            <cite>— Aniqa Napisa, Founder of Kape En Ato</cite>
        </div>

    </div>
</section>

<style>
/* ===== OUR STORY SECTION STYLES ===== */
.story-section {
    padding: 80px 0;
}
.story-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 28px;
    margin-top: 55px;
}
.story-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border-subtle);
    border-radius: 16px;
    padding: 32px 28px;
    position: relative;
    transition: transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}
.story-card:hover {
    transform: translateY(-6px);
    border-color: var(--amber);
    box-shadow: 0 12px 40px rgba(232, 160, 64, 0.12);
}
.story-year {
    display: inline-block;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--amber);
    background: rgba(232, 160, 64, 0.1);
    border: 1px solid rgba(232, 160, 64, 0.25);
    padding: 4px 12px;
    border-radius: 20px;
    margin-bottom: 18px;
}
.story-icon {
    font-size: 2.2rem;
    margin-bottom: 14px;
    display: block;
}
.story-card h3 {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 10px;
}
.story-card p {
    font-size: 0.88rem;
    color: var(--text-secondary);
    line-height: 1.75;
    margin: 0;
}
.story-quote {
    margin-top: 64px;
    text-align: center;
    padding: 48px 40px;
    background: rgba(232, 160, 64, 0.05);
    border: 1px solid rgba(232, 160, 64, 0.2);
    border-radius: 20px;
    position: relative;
}
.story-quote::before {
    content: '"';
    position: absolute;
    top: -28px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 6rem;
    color: var(--amber);
    opacity: 0.25;
    font-family: Georgia, serif;
    line-height: 1;
}
.story-quote blockquote {
    font-size: 1.25rem;
    font-style: italic;
    color: var(--text-primary);
    line-height: 1.7;
    margin: 0 0 16px 0;
    max-width: 700px;
    margin-left: auto;
    margin-right: auto;
}
.story-quote cite {
    font-size: 0.85rem;
    color: var(--amber);
    font-style: normal;
    letter-spacing: 1px;
    font-weight: 600;
}
@media (max-width: 600px) {
    .story-grid { grid-template-columns: 1fr; }
    .story-quote blockquote { font-size: 1rem; }
    .story-quote { padding: 36px 24px; }
}
</style>

<hr class="divider">

<!-- ===== UPDATED FOOTER[cite: 25] ===== -->
<!-- Description: Website footer with logo, social media, and copyright.
Function: Provides branding, social links, and legal information.
Technical: Centered layout, external social media link, copyright notice. -->
<footer>
    <div class="footer-logo">Kape Inato</div>
    
    <!-- SOCIAL MEDIA LINK[cite: 25] -->
    <!-- Description: Instagram social media link.
    Function: Allows visitors to follow the cafe on Instagram.
    Technical: External link with target="_blank", image and text styling. -->
    <div style="margin: 25px 0;">
        <a href="https://www.instagram.com/k.kapeinato/" target="_blank" class="social-link" style="text-decoration: none;">
            <img src="instagram.jpg" alt="Instagram">
            <p style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 8px; letter-spacing: 1px;">FOLLOW US @K.KAPEINATO</p>
        </a>
    </div>

    <p>&copy; 2024 Kape Inato &mdash; Panda Tea, J.A. Clarins Street, Dao, Tagbilaran, Bohol.</p>
</footer>

</body>
</html>