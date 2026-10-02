/* Chart tooltips.
 *
 * The charts already work without this file: every mark carries an SVG
 * <title>, which browsers render as a native tooltip. This adds a faster,
 * styled one on top - and nothing here changes what the charts say.
 *
 * It reads `data-label` / `data-value` off the invisible `.hit` circles the
 * macros emit, so it never has to re-derive a value from pixel coordinates.
 */
(function (window, document) {
  "use strict";

  var tip = null;

  function ensureTip() {
    if (!tip) {
      tip = document.createElement("div");
      tip.className = "chart-tooltip";
      tip.setAttribute("role", "presentation");
      document.body.appendChild(tip);
    }
    return tip;
  }

  function show(target, event) {
    var label = target.getAttribute("data-label");
    var value = target.getAttribute("data-value");
    if (!label && !value) return;

    var el = ensureTip();
    el.textContent = label && value ? label + ": " + value : label || value;
    el.classList.add("show");

    // Positioned against the viewport (the tooltip is position: fixed), and
    // nudged left near the right edge so it never runs off screen.
    var box = el.getBoundingClientRect();
    var x = event.clientX + 14;
    var y = event.clientY - box.height - 10;
    if (x + box.width > window.innerWidth - 8) x = event.clientX - box.width - 14;
    if (y < 8) y = event.clientY + 18;
    el.style.left = x + "px";
    el.style.top = y + "px";
  }

  function hide() {
    if (tip) tip.classList.remove("show");
  }

  /* Only the line charts get this. Bars and heat cells are already directly
   * labelled with their value, so a hover tooltip would repeat what is
   * printed beside them - their <title> children are enough.
   *
   * Delegated from the document: one listener instead of one per mark. */
  document.addEventListener("mouseover", function (event) {
    var target = event.target.closest ? event.target.closest(".hit") : null;
    if (target) show(target, event);
  });

  document.addEventListener("mousemove", function (event) {
    var target = event.target.closest ? event.target.closest(".hit") : null;
    if (target) show(target, event);
    else hide();
  });

  document.addEventListener("mouseout", function (event) {
    var target = event.target.closest ? event.target.closest(".hit") : null;
    if (target) hide();
  });

  /* Scrolling moves the chart out from under a fixed-position tooltip, which
   * would otherwise hang there pointing at nothing. */
  window.addEventListener("scroll", hide, { passive: true });

  /* The tooltip inherits its colours from CSS custom properties, so a theme
   * switch repaints it automatically - but a tooltip left open across the
   * switch would flash the old surface, so it is simply dismissed. */
  document.addEventListener("fq:themechange", hide);
})(window, document);
