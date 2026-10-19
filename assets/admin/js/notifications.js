/**
 * Lümia Factures - Notifications (toasts)
 *
 * Toast part of lumia-tools' notifications.js, loaded only on the plugin page.
 * No notification drawer: lumia-factures never captures other plugins' notices.
 * Distinct names from lumia-tools, which runs its own notifications.js on every
 * admin page: container #lumia-factures-toast-container, data
 * window.lumiaFacturesToast, API window.lumiaFacturesShowToast.
 */
(function () {
  "use strict";

  var TOAST_DURATION = 4000;

  var CLOSE_SVG =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';

  // Translated strings come from PHP: lumiaFacturesAdmin.i18n (Admin::enqueue_assets()).
  function t(key) {
    var data = window.lumiaFacturesAdmin;
    return (data && data.i18n && data.i18n[key]) || "";
  }

  function showToast(message, type) {
    var container = document.getElementById("lumia-factures-toast-container");
    if (!container) return;

    type = /^(success|error|warning|info)$/.test(type || "") ? type : "success";

    var toast = document.createElement("div");
    toast.className = "lumia-toast lumia-toast--" + type;
    toast.setAttribute("role", "alert");
    toast.setAttribute("aria-live", "assertive");

    toast.innerHTML =
      '<div class="lumia-toast__inner">' +
      '<span class="lumia-toast__dot"></span>' +
      '<p class="lumia-toast__message">' + escapeHtml(message) + "</p>" +
      '<button class="lumia-toast__close" type="button" aria-label="' + escapeAttr(t("close")) + '">' + CLOSE_SVG + "</button>" +
      "</div>" +
      '<div class="lumia-toast__progress-bar"><span class="lumia-toast__progress-fill"></span></div>';

    container.appendChild(toast);

    var closeBtn = toast.querySelector(".lumia-toast__close");
    var fill = toast.querySelector(".lumia-toast__progress-fill");

    var dismissed = false;
    var elapsed = 0;
    var lastTick = Date.now();
    var raf;

    // Animated entrance
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        toast.classList.add("is-visible");
      });
    });

    function tick() {
      if (dismissed) return;
      var now = Date.now();
      elapsed += now - lastTick;
      lastTick = now;
      fill.style.transform = "scaleX(" + Math.max(0, 1 - elapsed / TOAST_DURATION) + ")";
      if (elapsed >= TOAST_DURATION) {
        dismiss();
        return;
      }
      raf = requestAnimationFrame(tick);
    }

    setTimeout(function () {
      lastTick = Date.now();
      raf = requestAnimationFrame(tick);
    }, 50);

    function dismiss() {
      if (dismissed) return;
      dismissed = true;
      cancelAnimationFrame(raf);
      toast.classList.remove("is-visible");
      toast.classList.add("is-hiding");
      toast.addEventListener(
        "transitionend",
        function () {
          if (toast.parentNode) toast.parentNode.removeChild(toast);
        },
        { once: true },
      );
    }

    closeBtn.addEventListener("click", dismiss);

    // Pause on hover
    toast.addEventListener("mouseenter", function () {
      cancelAnimationFrame(raf);
    });
    toast.addEventListener("mouseleave", function () {
      if (!dismissed) {
        lastTick = Date.now();
        raf = requestAnimationFrame(tick);
      }
    });
  }

  function escapeHtml(str) {
    var d = document.createElement("div");
    d.textContent = str;
    return d.innerHTML;
  }

  function escapeAttr(str) {
    return escapeHtml(str).replace(/"/g, "&quot;");
  }

  document.addEventListener("DOMContentLoaded", function () {
    var data = window.lumiaFacturesToast;
    if (data && data.message) showToast(data.message, data.type);
  });

  // Public API for the modules.
  window.lumiaFacturesShowToast = showToast;
})();
