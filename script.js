/* =========================================================
   ScamBuster — script.js
   Optional progressive-enhancement effects only.
   The page reads perfectly with JS disabled. Every effect
   here checks prefers-reduced-motion and degrades to nothing.
   ========================================================= */
(function () {
  "use strict";

  var reduceMotion = window.matchMedia &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---- Scroll reveal: fade sections in as they enter view ---- */
  var revealEls = document.querySelectorAll(".reveal");

  if (reduceMotion || !("IntersectionObserver" in window)) {
    // No motion wanted, or no support: show everything immediately.
    revealEls.forEach(function (el) { el.classList.add("is-visible"); });
  } else {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
          io.unobserve(entry.target);
        }
      });
    }, { rootMargin: "0px 0px -10% 0px", threshold: 0.08 });

    revealEls.forEach(function (el) { io.observe(el); });
  }

  /* ---- One-time typewriter reveal on the hero tagline ---- */
  var tagline = document.querySelector(".hero__tagline");

  if (tagline && !reduceMotion) {
    var text = tagline.textContent;
    tagline.textContent = "";
    tagline.setAttribute("aria-label", text); // keep it readable to AT
    var i = 0;

    function type() {
      // Fail-safe: if anything goes wrong, restore the full string.
      if (i <= text.length) {
        tagline.textContent = text.slice(0, i);
        i += 1;
        window.setTimeout(type, 55);
      } else {
        tagline.textContent = text;
      }
    }
    // Small delay so the hero settles first.
    window.setTimeout(type, 350);
  }
})();
