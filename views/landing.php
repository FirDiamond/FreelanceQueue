<?php
// Anonymous homepage — the vermilion poster, ported faithfully from the
// standalone Vite landing. The signature "queue core" is now a live ASCII-art
// aperture: a rotating starburst of shifting binary, slashes and status glyphs,
// painted on a timer by ascii.js (data-ascii="core"). Hovering it spins the
// rotation up and tightens the character cadence.
//
// This view runs full-bleed with chrome:false, so it draws its own poster
// header (brand, sync dot, nav, theme toggle) instead of the shared app nav.

$__manifesto = [
    'When chaos aligns, the backlog clears.',
    'A steady stream through shifting years.',
    'Each task begins, no friction found —',
    'Just pipelines smoothly turning round.',
];
$__features = [
    ['Now serving', 'A live card with an elapsed timer. Call next, done, skip — one tap each.'],
    ['Self-updating', 'Reorder the waiting list and every position and ETA follows on the next poll.'],
    ['Email at the right moment', 'Joined, almost your turn, started, completed — sent so nobody has to refresh.'],
];
// Guest-facing "how it works" — the whole product in four plain steps.
$__steps = [
    ['Open your queue', 'Flip your queue open for the day. Clients can only join while it is open.'],
    ['Clients join from their phone', 'They browse your services, pick one, and take a numbered ticket — no app to install.'],
    ['Call, serve, complete', 'Work the live now-serving card: call next, mark done, skip or no-show in one tap.'],
    ['Everyone stays in sync', 'Positions, wait estimates and email alerts update on their own — nobody refreshes.'],
];
$__since = gmdate('c', time() - 872); // ~14:32 into the current job, for the preview
?>
<script>document.documentElement.classList.add('has-js');</script>
<div class="landing poster">
  <!-- hairline poster frame + corner registration marks -->
  <div class="poster-frame" aria-hidden="true">
    <span class="edge"></span>
    <span class="mark tl"></span><span class="mark tr"></span>
    <span class="mark bl"></span><span class="mark br"></span>
  </div>

  <section class="poster-hero">
    <header class="poster-head">
      <div>
        <a class="brand-big" href="<?= e(url('auth.index')) ?>">Freelance<span>Queue</span></a>
        <p class="sync">
          <span class="sync-dot"><span class="ring"></span><span class="core"></span></span>
          Synced · <?= e(strtoupper(date('j M'))) ?>
        </p>
      </div>
      <nav class="poster-nav">
        <a href="#tour">Tour</a>
        <a href="#tour">Pricing</a>
        <a href="<?= e(url('auth.login')) ?>" class="signin">Sign in</a>
        <button type="button" id="theme-toggle" class="poster-theme"
                aria-label="Switch between light and dark theme">
          <span id="theme-toggle-label">Theme</span>
        </button>
      </nav>
    </header>

    <!-- centerpiece: the live ASCII queue aperture, painted by ascii.js -->
    <div class="poster-core">
      <div class="ascii-core" data-ascii="core" role="img" tabindex="0"
           aria-label="FreelanceQueue live queue aperture: a rotating starburst rendered from shifting binary digits, slashes and status glyphs"></div>
      <p class="qcore-caption">Hover to spin up the stream</p>
    </div>

    <!-- thesis + manifesto + stats -->
    <div class="poster-thesis">
      <div class="poster-thesis-main">
        <p class="eyebrow">Queue management for freelancers</p>
        <h1>Let the backlog<br>run itself.</h1>

        <button type="button" class="manifesto" id="open-pipeline"
                aria-label="Open a live preview of the active client pipeline">
          <?php foreach ($__manifesto as $__line): ?>
            <span style="display:block"><?= e($__line) ?></span>
          <?php endforeach; ?>
          <span class="see">View the live pipeline →</span>
        </button>

        <div class="landing-cta">
          <a class="btn btn-primary" href="<?= e(url('auth.signup')) ?>">Open your queue</a>
          <a class="btn" href="<?= e(url('auth.login')) ?>">Sign in</a>
        </div>
      </div>

      <div class="poster-stats">
        <p class="hot">42 in queue today</p>
        <p>avg wait · 18 min</p>
        <p><a href="#tour">scroll to tour ↓</a></p>
      </div>
    </div>
  </section>

  <!-- ───────── HOW IT WORKS ───────── -->
  <section class="poster-how" id="how">
    <div class="how-inner">
      <p class="eyebrow reveal">How it works</p>
      <h2 class="reveal">From open to done,<br>in four steps.</h2>
      <div class="how-steps">
        <?php foreach ($__steps as $__i => $__s): ?>
          <div class="how-step reveal">
            <span class="how-num"><?= sprintf('%02d', $__i + 1) ?></span>
            <p class="how-k"><?= e($__s[0]) ?></p>
            <p class="how-d"><?= e($__s[1]) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ───────── TOUR / SHOWCASE ───────── -->
  <section class="poster-tour" id="tour">
    <div class="tour-inner">
      <p class="eyebrow reveal">The tour</p>
      <h2 class="reveal">One stream. Every client.<br>Nobody refreshing.</h2>
      <p class="tour-lead reveal">
        The freelancer opens the queue; clients join from their own phone. Everyone
        watches the same line move — from opposite sides of the screen.
      </p>

      <div class="mockups reveal">
        <!-- freelancer dashboard, in a browser frame -->
        <div class="mock-browser">
          <div class="mock-bar">
            <span class="dots"><i></i><i></i><i></i></span>
            <span class="url">app.freelancequeue.io/dashboard</span>
          </div>
          <div class="mock-body">
            <div class="mock-serving">
              <p class="eyebrow" style="margin:0">Now serving</p>
              <div class="row">
                <span class="who">Current client</span>
                <span class="clock">00:14:32</span>
              </div>
              <p class="svc">Website audit · #A13</p>
            </div>
            <div class="mock-wait">
              <p class="eyebrow" style="margin:0">Waiting · 3</p>
              <span class="bar" style="width:82%"></span>
              <span class="bar dim" style="width:64%"></span>
              <span class="bar dim" style="width:70%"></span>
            </div>
            <div class="mock-stats">
              <div><span class="v">12</span><span class="k">Completed</span></div>
              <div><span class="v">4.2M</span><span class="k">Earned Rp</span></div>
            </div>
          </div>
        </div>

        <!-- client side: app mark + ticket -->
        <div class="mock-client">
          <div class="mock-icon">
            <div class="glyph"><span></span></div>
            <p>FreelanceQueue</p>
          </div>
          <div class="mock-ticket">
            <p class="eyebrow" style="margin:0">Your ticket</p>
            <p class="tk">#A15</p>
            <div class="tk-line">
              <p class="pos">2nd</p>
              <p class="meta">in line · ETA ~15 min</p>
            </div>
          </div>
        </div>
      </div>

      <div class="tour-features">
        <?php foreach ($__features as $__f): ?>
          <div class="feat reveal">
            <p class="k">— <?= e($__f[0]) ?></p>
            <p class="d"><?= e($__f[1]) ?></p>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="tour-cta reveal">
        <p class="lead">Open your queue for the day.</p>
        <div class="acts">
          <a class="btn btn-primary" href="<?= e(url('auth.signup')) ?>">Create your account</a>
          <button type="button" class="btn ghost" id="open-pipeline-2">See it live</button>
        </div>
      </div>

      <footer class="tour-foot">
        <span>FreelanceQueue</span>
        <span>Built with care · no refresh, ever</span>
        <span>© 2026</span>
      </footer>
    </div>
  </section>
</div>

<!-- Live pipeline slide-over -->
<div class="pipeline-scrim" id="pipeline-scrim" tabindex="-1"></div>
<aside class="pipeline-panel" id="pipeline-panel" role="dialog" aria-modal="true" aria-label="Active client pipeline">
  <div class="card-head">
    <div>
      <p class="eyebrow" style="margin:0 0 0.35rem"><span class="ember-dot"></span> Live · Queue open</p>
      <h2 style="margin:0">Active pipeline</h2>
      <p class="poll-note" style="margin:0.2rem 0 0">Sample queue — <?= e(strtoupper(date('j M'))) ?></p>
    </div>
    <button type="button" class="btn sm card-head-actions" id="close-pipeline">Close</button>
  </div>

  <div class="now-serving" style="margin-top:1rem">
    <div class="ns-top">
      <span class="ticket">A-013</span>
      <span class="ns-name">Current client</span>
      <span class="badge serving"><span class="dot"></span>Serving</span>
      <span class="ns-timer" data-started="<?= e($__since) ?>">--:--</span>
    </div>
    <div class="ns-meta">Website audit · Rp 850,000</div>
  </div>

  <p class="eyebrow" style="margin:1.4rem 0 0.5rem">Waiting · 3</p>
  <div class="queue-list">
    <?php
    $__preview = [
        ['A-015', 'Next client', 'Logo refinement', '~15 min'],
        ['A-016', 'Second in line', 'Landing page audit', '~40 min'],
        ['A-017', 'Third in line', 'Brand consult', '~1 hr 10 min'],
    ];
    foreach ($__preview as $__i => $__row): ?>
      <div class="queue-row">
        <div class="pos"><?= $__i + 1 ?></div>
        <div class="who">
          <div class="name"><span class="ticket"><?= e($__row[0]) ?></span> <?= e($__row[1]) ?></div>
          <div class="svc"><?= e($__row[2]) ?></div>
        </div>
        <div class="eta nowrap"><?= e($__row[3]) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="poll-note" style="margin-top:1rem">ETAs blend history with the quote · updates every 5s, no refresh</p>
</aside>

<script>
  (function () {
    var scrim = document.getElementById("pipeline-scrim");
    var panel = document.getElementById("pipeline-panel");
    var closeBtn = document.getElementById("close-pipeline");
    var openers = ["open-pipeline", "open-pipeline-2"];
    var lastFocus = null;

    function open() {
      lastFocus = document.activeElement;
      scrim.classList.add("open"); panel.classList.add("open");
      closeBtn.focus();
    }
    function close() {
      scrim.classList.remove("open"); panel.classList.remove("open");
      if (lastFocus) lastFocus.focus();
    }
    openers.forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener("click", open);
    });
    closeBtn.addEventListener("click", close);
    scrim.addEventListener("click", close);
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") close(); });

    // Self-contained elapsed timer for the preview (poll.js isn't loaded here).
    var timer = panel.querySelector(".ns-timer");
    var started = Date.parse(timer.getAttribute("data-started"));
    function pad(n) { return (n < 10 ? "0" : "") + n; }
    function tick() {
      if (isNaN(started)) { timer.textContent = "--:--"; return; }
      var s = Math.max(0, Math.floor((Date.now() - started) / 1000));
      var h = Math.floor(s / 3600), mm = Math.floor((s % 3600) / 60), sec = s % 60;
      timer.textContent = h > 0 ? h + ":" + pad(mm) + ":" + pad(sec) : pad(mm) + ":" + pad(sec);
    }
    tick();
    setInterval(tick, 1000);
  })();
</script>

<script>
  /* Scroll reveal: fade + rise elements in as they enter the viewport, and fade
     them back out as they leave — both directions, so scrolling down feels
     alive. Gated on .has-js (set in <head>-order above) so with scripting off
     every .reveal stays fully visible; reduced-motion users skip the animation. */
  (function () {
    var els = document.querySelectorAll(".reveal");
    if (!els.length) return;

    var reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduce || !("IntersectionObserver" in window)) {
      els.forEach(function (el) { el.classList.add("in"); });
      return;
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        entry.target.classList.toggle("in", entry.isIntersecting);
      });
    }, { threshold: 0.15, rootMargin: "0px 0px -8% 0px" });

    els.forEach(function (el) { io.observe(el); });
  })();
</script>

<script>
  /* Pinned aperture: once the page scrolls, the ASCII core stays fixed to the
     screen (it no longer scrolls away with the hero), slides to the right and
     fades — and it sits behind everything, so the copy reads over the top of
     it. At the very top it's back in its original spot as the centerpiece.
     Gated on .has-js and skipped for reduced-motion (it then stays in flow). */
  (function () {
    var core = document.querySelector(".ascii-core");
    if (!core) return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

    var base = null;              // pinned viewport coords, captured from flow

    function measure() {
      // drop back to natural flow to read where the centerpiece really sits
      core.style.position = core.style.top = core.style.left = "";
      core.style.width = core.style.transform = core.style.zIndex = "";
      var r = core.getBoundingClientRect();
      var sx = window.scrollX || window.pageXOffset || 0;
      var sy = window.scrollY || window.pageYOffset || 0;
      base = { top: r.top + sy, left: r.left + sx, width: r.width };
      // pin it to that spot, behind all content
      core.style.position = "fixed";
      core.style.top = base.top + "px";
      core.style.left = base.left + "px";
      core.style.width = base.width + "px";
      core.style.margin = "0";
      core.style.zIndex = "-1";
      update();
    }

    function update() {
      if (!base) return;
      var y = window.scrollY || window.pageYOffset || 0;
      var range = window.innerHeight * 0.7;            // fully drifted by ~70vh
      var p = Math.min(1, Math.max(0, y / range));     // 0 at top -> 1 scrolled
      core.style.transform = "translateX(" + (p * 45).toFixed(2) + "%)";
      core.style.opacity = (1 - p * 0.85).toFixed(3);  // down to ~0.15
    }

    var ticking = false;
    function onScroll() {
      if (!ticking) { ticking = true; requestAnimationFrame(function () { ticking = false; update(); }); }
    }
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", measure, { passive: true });
    // measure after layout + the aperture's first paint so the rect is real
    if (document.readyState === "complete") { measure(); }
    else { window.addEventListener("load", measure); }
  })();
</script>
