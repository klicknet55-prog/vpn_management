<?php require_once __DIR__ . '/system/seo.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php seoRenderHeadTags('Home'); ?>
  <link rel="stylesheet" href="landing-style.css">
</head>
<body>
  <?php seoRenderBodyOpenTags(); ?>
  <header class="header">
    <div class="container">
      <a href="index.php" class="logo">
        <div class="logo-flex">
          <div class="logo-icon"></div>
          <span class="logo-company-name"><?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      </a>
      <nav class="nav">
        <a href="#features">Features</a>
        <a href="#about">About</a>
        <a href="#contact">Contact</a>
      </nav>
      <a href="#get-started" class="btn-primary">Get Started</a>
    </div>
  </header>
  <section class="hero">
    <div class="container">
      <div class="hero-content">
        <h1>Build Your Next Project with Us</h1>
        <p>Modern, clean, and responsive landing page for your business or agency. Fast to launch, easy to customize.</p>
        <a href="#get-started" class="btn-primary">Start Now</a>
      </div>
      <div class="hero-image">
        <img src="uploads/hero.png" alt="Hero Illustration">
      </div>
    </div>
  </section>
  <section class="features" id="features">
    <div class="container">
      <h2>Features</h2>
      <div class="features-list">
        <div class="feature-item">
          <h3>Responsive Design</h3>
          <p>Looks great on desktop, tablet, and mobile devices.</p>
        </div>
        <div class="feature-item">
          <h3>Easy Customization</h3>
          <p>Simple code structure for quick edits and branding.</p>
        </div>
        <div class="feature-item">
          <h3>Modern Aesthetic</h3>
          <p>Clean, minimal, and professional look for any business.</p>
        </div>
      </div>
    </div>
  </section>
  <section class="about" id="about">
    <div class="container">
      <h2>About Us</h2>
      <p>We help startups and businesses launch beautiful web experiences quickly. Our team specializes in modern web design and development.</p>
    </div>
  </section>
  <section class="contact" id="contact">
    <div class="container">
      <h2>Contact</h2>
      <form class="contact-form">
        <input type="text" placeholder="Your Name" required>
        <input type="email" placeholder="Your Email" required>
        <textarea placeholder="Your Message" required></textarea>
        <button type="submit" class="btn-primary">Send Message</button>
      </form>
    </div>
  </section>
  <footer class="footer">
    <div class="container">
      <span>&copy; 2026 <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?>. All rights reserved.</span>
    </div>
  </footer>
  <script src="templatemo-daynight-script.js"></script>
  <script>if(window.applyWebBrandingLogo) applyWebBrandingLogo();</script>
</body>
</html>
