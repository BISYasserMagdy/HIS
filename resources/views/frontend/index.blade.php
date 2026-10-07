<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pharos HIS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --navy-deep: #0a1830;
      --navy: #102a4d;
      --beam: #ffb454;
      --foam: #f5f1e8;
      --ink: #1c2433;
      --mist: #6b7686;
      --teal: #2bb8a8;
      --line: #e3ddcd;
    }

    * { box-sizing: border-box; }

    html { -webkit-font-smoothing: antialiased; }

    body {
      margin: 0;
      font-family: 'Inter', system-ui, sans-serif;
      background: var(--foam);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem 1rem;
      color: var(--ink);
    }

    a:focus-visible, button:focus-visible {
      outline: 2px solid var(--teal);
      outline-offset: 3px;
    }

    .container {
      background: #fff;
      border-radius: 22px;
      box-shadow: 0 30px 70px -20px rgba(10, 24, 48, 0.35);
      overflow: hidden;
      max-width: 960px;
      width: 100%;
      display: flex;
      flex-wrap: wrap;
    }

    /* ───────────────────── LEFT: the beacon ───────────────────── */
    .left-panel {
      flex: 1.1;
      min-width: 320px;
      position: relative;
      background: radial-gradient(120% 140% at 18% -10%, #1b3a63 0%, var(--navy) 38%, var(--navy-deep) 100%);
      color: #fff;
      padding: 3.2rem 2.6rem;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      overflow: hidden;
      isolation: isolate;
    }

    /* rotating beam: a soft wedge of light sweeping from a fixed point */
    .beam-source {
      position: absolute;
      top: -8%;
      left: 38%;
      width: 240px;
      height: 240px;
      transform-origin: 50% 50%;
      z-index: 0;
      animation: sweep 9s linear infinite;
    }

    .beam-source::before {
      content: "";
      position: absolute;
      inset: 0;
      background: conic-gradient(from 0deg,
          rgba(255, 180, 84, 0) 0deg,
          rgba(255, 180, 84, 0.55) 8deg,
          rgba(255, 180, 84, 0) 26deg,
          rgba(255, 180, 84, 0) 360deg);
      border-radius: 50%;
      filter: blur(2px);
    }

    @keyframes sweep {
      from { transform: rotate(0deg); }
      to   { transform: rotate(360deg); }
    }

    @media (prefers-reduced-motion: reduce) {
      .beam-source { animation: none; }
      .beam-source::before {
        background: radial-gradient(circle at 50% 50%, rgba(255, 180, 84, 0.35), rgba(255, 180, 84, 0) 70%);
      }
    }

    .left-content { position: relative; z-index: 1; }

    .eyebrow {
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.72rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--beam);
      opacity: 0.9;
      margin: 0 0 1.1rem;
    }

    .wordmark {
      font-family: 'Fraunces', serif;
      font-weight: 600;
      font-size: 3.1rem;
      line-height: 1;
      margin: 0;
      letter-spacing: -0.01em;
    }

    .wordmark span {
      display: block;
      font-size: 1.05rem;
      font-weight: 400;
      font-style: italic;
      color: rgba(255, 255, 255, 0.65);
      margin-top: 0.35rem;
      letter-spacing: 0.01em;
    }

    .left-panel p.tagline {
      font-size: 1rem;
      color: rgba(255, 255, 255, 0.78);
      line-height: 1.65;
      max-width: 360px;
      margin: 1.6rem 0 0;
    }

    .coords {
      position: relative;
      z-index: 1;
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.72rem;
      color: rgba(255, 255, 255, 0.45);
      letter-spacing: 0.04em;
      padding-top: 2rem;
      border-top: 1px solid rgba(255, 255, 255, 0.12);
      margin-top: 2rem;
    }

    /* ───────────────────── RIGHT: quick access ───────────────────── */
    .right-panel {
      flex: 1;
      min-width: 320px;
      padding: 3.2rem 2.6rem;
      display: flex;
      flex-direction: column;
    }

    .right-panel h2 {
      margin: 0 0 0.35rem;
      color: var(--ink);
      font-family: 'Fraunces', serif;
      font-weight: 600;
      font-size: 1.5rem;
    }

    .right-panel .sub {
      margin: 0 0 1.8rem;
      color: var(--mist);
      font-size: 0.92rem;
    }

    .nav-links {
      display: flex;
      flex-direction: column;
      gap: 0.7rem;
    }

    .nav-link {
      display: flex;
      align-items: center;
      gap: 1rem;
      padding: 0.95rem 1.1rem;
      background: #fff;
      border-radius: 12px;
      text-decoration: none;
      color: var(--ink);
      font-weight: 600;
      font-size: 0.97rem;
      border: 1.5px solid var(--line);
      transition: border-color 0.2s ease, transform 0.2s ease, background 0.2s ease;
    }

    .nav-link:hover, .nav-link:focus-visible {
      border-color: var(--teal);
      background: #f3fbfa;
      transform: translateX(4px);
    }

    .nav-link .link-icon {
      flex: none;
      font-size: 1.25rem;
      width: 38px;
      height: 38px;
      background: var(--navy);
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .nav-link .label { flex: 1; }

    .nav-link .arrow {
      font-size: 1.1rem;
      color: var(--mist);
      transition: transform 0.2s ease, color 0.2s ease;
    }

    .nav-link:hover .arrow {
      color: var(--teal);
      transform: translateX(2px);
    }

    .footer-links {
      margin-top: auto;
      padding-top: 1.8rem;
      display: flex;
      gap: 1rem;
      font-size: 0.85rem;
    }

    .footer-links a {
      color: var(--mist);
      text-decoration: none;
    }

    .footer-links a:hover { color: var(--teal); }

    .footer-links .sep { color: var(--line); }

    @media (max-width: 640px) {
      .left-panel { padding: 2.4rem 1.8rem; }
      .right-panel { padding: 2.4rem 1.8rem; }
      .wordmark { font-size: 2.4rem; }
    }
  </style>
</head>

<body>
  <div class="container">
    <div class="left-panel">
      <div class="beam-source" aria-hidden="true"></div>
      <div class="left-content">
        <p class="eyebrow">Healthcare Information System</p>
        <h1 class="wordmark">PHAROS<span>a steady light for the whole hospital</span></h1>
        <p class="tagline">One point of entry for the people who keep care running — pharmacy, scheduling, and patient guidance, all signaled from the same beacon.</p>
      </div>
      <div class="coords">31.2156° N · 29.9187° E — Alexandria</div>
    </div>

    <div class="right-panel">
      <h2>Quick access</h2>
      <p class="sub">Pick where you're headed.</p>
      <div class="nav-links">
        <a href="Home_Page.html" class="nav-link">
          <span class="link-icon">🏠</span>
          <span class="label">Home Page</span>
          <span class="arrow">→</span>
        </a>
        <a href="ERP_Dashboard.html" class="nav-link">
          <span class="link-icon">💊</span>
          <span class="label">ERP Pharmacy System</span>
          <span class="arrow">→</span>
        </a>
        <a href="Appointments_Page.html" class="nav-link">
          <span class="link-icon">📅</span>
          <span class="label">Book Appointment</span>
          <span class="arrow">→</span>
        </a>
        <a href="Health_Chatbot.html" class="nav-link">
          <span class="link-icon">🤖</span>
          <span class="label">Health Chatbot</span>
          <span class="arrow">→</span>
        </a>
      </div>
      <div class="footer-links">
        <a href="#">Help</a>
        <span class="sep">|</span>
        <a href="#">Contact</a>
      </div>
    </div>
  </div>
</body>

</html>
