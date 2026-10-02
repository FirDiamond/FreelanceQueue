/* Live updates without WebSockets.
 *
 * Two rules shape everything here:
 *
 * 1. The page is already correct when it arrives. Every value this file
 *    patches is server-rendered first, so a broken or blocked script costs
 *    freshness, never correctness.
 * 2. Polling stops when nobody is looking. A client tab left open all
 *    afternoon should cost nothing while it is in the background, which is
 *    what `visibilitychange` is for.
 */
(function (window, document) {
  "use strict";

  var INTERVAL_MS = 5000;
  var BACKOFF_MAX_MS = 60000;

  /* --- helpers ---------------------------------------------------------- */

  function setText(id, value) {
    var el = document.getElementById(id);
    if (el && value !== null && value !== undefined && el.textContent !== String(value)) {
      el.textContent = value;
    }
  }

  function isTyping() {
    // Reloading the page under someone mid-sentence - the amount charged, a
    // note - throws away what they typed. Freshness is never worth that.
    var el = document.activeElement;
    if (!el) return false;
    var tag = el.tagName;
    return tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT";
  }

  function pad(n) {
    return n < 10 ? "0" + n : String(n);
  }

  /* A poller that sleeps while the tab is hidden and backs off on failure.
   * `onData` returns true to keep polling, false to stop for good. */
  function Poller(url, onData) {
    var timer = null;
    var delay = INTERVAL_MS;
    var stopped = false;

    function schedule() {
      if (stopped || document.visibilityState === "hidden") return;
      clearTimeout(timer);
      timer = setTimeout(tick, delay);
    }

    function tick() {
      if (stopped || document.visibilityState === "hidden") return;
      fetch(url, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
        cache: "no-store"
      })
        .then(function (res) {
          if (res.status === 401 || res.status === 403 || res.status === 404) {
            // Signed out, or the ticket is no longer ours. Retrying would
            // just hammer a wall; a reload lands on the login page instead.
            stopped = true;
            if (res.status !== 404) window.location.reload();
            return null;
          }
          if (!res.ok) throw new Error("HTTP " + res.status);
          return res.json();
        })
        .then(function (data) {
          if (data === null) return;
          delay = INTERVAL_MS;
          if (onData(data) === false) stopped = true;
        })
        .catch(function () {
          // Server restart, flaky wifi, laptop lid. Slow down rather than
          // give up - the tab is often still wanted.
          delay = Math.min(delay * 2, BACKOFF_MAX_MS);
        })
        .then(schedule);
    }

    document.addEventListener("visibilitychange", function () {
      if (document.visibilityState === "visible") {
        delay = INTERVAL_MS;
        tick(); // Catch up immediately rather than after another 5 s.
      } else {
        clearTimeout(timer);
      }
    });

    schedule();
    return {
      stop: function () {
        stopped = true;
        clearTimeout(timer);
      }
    };
  }

  /* --- elapsed timer ---------------------------------------------------- */

  /* Counts up from a stored UTC timestamp entirely client-side. A ticking
   * clock is the one thing on the dashboard that genuinely changes every
   * second, and it needs no server round-trip to do it. */
  function elapsedTimer(id) {
    var el = document.getElementById(id);
    if (!el) return;

    var started = Date.parse(el.getAttribute("data-started") || "");
    if (isNaN(started)) {
      el.textContent = "--:--";
      return;
    }

    function paint() {
      var secs = Math.max(0, Math.floor((Date.now() - started) / 1000));
      var h = Math.floor(secs / 3600);
      var m = Math.floor((secs % 3600) / 60);
      var s = secs % 60;
      el.textContent = h > 0
        ? h + ":" + pad(m) + ":" + pad(s)
        : pad(m) + ":" + pad(s);
    }

    paint();
    setInterval(paint, 1000);
  }

  /* --- freelancer dashboard --------------------------------------------- */

  /* `signature` digests the queue's *structure* - who is being served, the
   * order of everyone waiting, how many are done. Cheap numbers get patched
   * in place; a structural change needs the server's markup, so reload. */
  function pollQueue(signature) {
    var known = signature;

    Poller("/api/queue", function (data) {
      setText("stat-waiting", data.waiting_count);
      setText("stat-done", data.stats.done_count);
      setText("stat-wait", data.stats.avg_wait_text);
      setText("stat-earned", data.stats.earned_text);

      if (data.serving) setText("ns-remaining", data.serving.remaining_text);

      if (data.signature !== known) {
        if (isTyping()) return true; // Try again next tick.
        known = data.signature;
        window.location.reload();
      }
      return true;
    });
  }

  /* --- client ticket page ----------------------------------------------- */

  /* The client's screen has no forms worth protecting and far fewer moving
   * parts, so it patches text directly and only reloads when the ticket
   * changes status - the moment the page's whole shape changes. */
  function pollTicket(entryId, status) {
    var known = status;

    Poller("/api/ticket/" + encodeURIComponent(entryId), function (data) {
      if (data.status !== known) {
        window.location.reload();
        return false; // Stop: the reload replaces this poller anyway.
      }

      setText("tk-position", data.position === null ? "-" : data.position);
      setText("tk-phrase", data.phrase);
      setText("tk-eta", data.eta_text || "-");
      setText("tk-waiting-count", data.waiting_count);
      setText("tk-serving", data.serving_ticket || "Nobody yet");
      return true;
    });
  }

  window.FQ = {
    elapsedTimer: elapsedTimer,
    pollQueue: pollQueue,
    pollTicket: pollTicket
  };
})(window, document);
