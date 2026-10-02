/* FreelanceQueue ASCII animations.
 *
 * No framework, no canvas — every "moving part" on the redesigned frontend is
 * monospace text repainted on a timer. One engine drives three motifs, chosen
 * per element via data-ascii="core|stream|pulse":
 *
 *   core   — the landing signature: a rotating, multi-pronged ASCII aperture
 *            rendered in stacked layers for faux-3D depth. Hovering spins it
 *            up and tightens the character cadence.
 *   stream — an ambient vertical ticker beside the auth cards.
 *   pulse  — a tiny breathing glyph for work pages (dashboard).
 *
 * Good citizen, like poll.js: it sleeps while the tab is hidden, and honours
 * prefers-reduced-motion by painting a single static frame and stopping.
 */
(function () {
  "use strict";

  var reduce = window.matchMedia
    && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  var BINARY = "01";
  var STATUS = "+×•◦·";           // micro status glyphs sprinkled along the prongs

  /* ---- core: rotating ASCII starburst aperture ------------------------ *
   * A symmetrical, multi-pronged queue aperture: PRONGS spiral blades
   * radiating from a pulsing hub, drawn entirely from shifting binary,
   * slashes and status glyphs. The whole field rotates on its axis while a
   * high-frequency cycle flickers the characters so it shimmers. Hovering
   * spins the rotation up AND tightens the cadence into a denser stream. */
  function makeCore(el) {
    var COLS = 50, ROWS = 24, ASPECT = 2.0;   // char cells are ~2x taller than wide
    var cx = (COLS - 1) / 2, cy = (ROWS - 1) / 2;
    var PRONGS = 8, TWIST = 2.6, GAMMA = 2.2; // blade count, spiral, spike sharpness

    var speed = 1, targetSpeed = 1;           // rotation + cycle multiplier
    var dens = 0, targetDens = 0;             // 0 rest -> 1 hover (fills more cells)

    el.addEventListener("mouseenter", function () { targetSpeed = 2.4; targetDens = 1; });
    el.addEventListener("mouseleave", function () { targetSpeed = 1;   targetDens = 0; });
    // keyboard focus counts as hover, so the caption's promise holds for a11y
    el.addEventListener("focus", function () { targetSpeed = 2.4; targetDens = 1; });
    el.addEventListener("blur",  function () { targetSpeed = 1;   targetDens = 0; });

    // Faux-3D extrusion: one crisp front layer plus three burnt-orange shadow
    // layers stacked behind it. Every layer shows the SAME frame; CSS offsets
    // and fades them (opacity 0.5/0.28/0.16) into volumetric depth, and masks
    // the stack into a lit aperture. The front layer (al-0) stays in normal
    // flow so it sizes the box; the shadows are absolutely pinned over it.
    var layers = [];
    [3, 2, 1, 0].forEach(function (i) {
      var span = document.createElement("span");
      span.className = "al al-" + i;
      span.setAttribute("aria-hidden", "true");   // parent carries role=img + label
      el.appendChild(span);
      layers[i] = span;
    });
    function paint(txt) {
      for (var i = 0; i < layers.length; i++) layers[i].textContent = txt;
    }

    function frame(t, d) {
      var out = "";
      for (var y = 0; y < ROWS; y++) {
        for (var x = 0; x < COLS; x++) {
          var nx = (x - cx) / cx;
          var ny = (y - cy) * ASPECT / cx;         // aspect-correct so it reads round
          var r = Math.sqrt(nx * nx + ny * ny);
          var ang = Math.atan2(ny, nx);

          // spiral blade angle: rotates with t, twists with radius (the aperture)
          var a = ang * PRONGS + t * 2.0 + r * TWIST;
          var petal = (Math.cos(a) + 1) / 2;       // 0..1, one lobe per prong
          var blade = Math.pow(petal, GAMMA);      // sharpen lobes into spikes

          var env = Math.max(0, 1 - r);            // fade to the disc edge
          var rim = Math.exp(-Math.pow((r - 0.5) / 0.42, 2)); // bright mid ring
          var v = blade * (0.55 * env + 0.7 * rim);

          // high-frequency shimmer: character choice churns many times a second
          var c = Math.floor(t * 9 + x * 1.7 + y * 0.9);
          var thr = 0.30 - d * 0.14;               // hover lowers it -> denser field

          if (r < 0.13) {
            out += (Math.floor(t * 3) % 2) ? "◆" : "◇";   // pulsing hub
          } else if (v < thr) {
            out += " ";
          } else if (v < thr + 0.16) {
            out += Math.sin(a) >= 0 ? "/" : "\\";          // faint blade edges
          } else if (v < 0.6) {
            out += BINARY[(c + x + y) % 2];                // streaming binary body
          } else if (v < 0.82) {
            out += ((c + x * 2 + y) % 4 === 0)
              ? STATUS[(c + y) % STATUS.length]            // occasional status glyph
              : "*";
          } else {
            out += "*";                                    // brightest prong spine
          }
        }
        out += "\n";
      }
      return out;
    }

    if (reduce) { paint(frame(0.8, 0)); el.style.setProperty("--lit", "0.7"); return; }

    var start = null;
    function loop(now) {
      if (document.visibilityState === "hidden") { el._raf = requestAnimationFrame(loop); return; }
      if (start === null) start = now;
      speed += (targetSpeed - speed) * 0.08;
      dens  += (targetDens  - dens)  * 0.08;
      var t = ((now - start) / 1000) * speed;
      paint(frame(t, dens));
      // depth lighting pulse: the aperture breathes with the rotation, brighter
      // toward the core (CSS reads --lit to modulate the glow + mask falloff)
      el.style.setProperty("--lit", (0.5 + 0.5 * Math.sin(t * 1.3)).toFixed(3));
      el._raf = requestAnimationFrame(loop);
    }
    el._raf = requestAnimationFrame(loop);
  }

  /* ---- stream: vertical binary ticker --------------------------------- */
  function makeStream(el) {
    var ROWS = 26, WIDTH = 5;
    var lines = [];
    function newline() {
      var s = "";
      for (var i = 0; i < WIDTH; i++) s += Math.random() < 0.5 ? BINARY[Math.floor(Math.random() * 2)] : " ";
      return s;
    }
    for (var i = 0; i < ROWS; i++) lines.push(newline());
    function paint() { el.textContent = lines.join("\n"); }

    if (reduce) { paint(); return; }

    var last = 0;
    function loop(now) {
      if (document.visibilityState !== "hidden") {
        if (now - last > 180) {
          lines.pop();
          lines.unshift(newline());
          paint();
          last = now;
        }
      }
      el._raf = requestAnimationFrame(loop);
    }
    el._raf = requestAnimationFrame(loop);
  }

  /* ---- pulse: single breathing glyph ---------------------------------- */
  function makePulse(el) {
    var frames = ["·", ":", "+", "*", "+", ":"];
    if (reduce) { el.textContent = "＊ live"; return; }
    var idx = 0, last = 0;
    function loop(now) {
      if (document.visibilityState !== "hidden" && now - last > 320) {
        el.textContent = frames[idx % frames.length] + " live";
        idx++;
        last = now;
      }
      el._raf = requestAnimationFrame(loop);
    }
    el._raf = requestAnimationFrame(loop);
  }

  function init() {
    document.querySelectorAll("[data-ascii]").forEach(function (el) {
      switch (el.getAttribute("data-ascii")) {
        case "core":   makeCore(el);   break;
        case "stream": makeStream(el); break;
        case "pulse":  makePulse(el);  break;
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
