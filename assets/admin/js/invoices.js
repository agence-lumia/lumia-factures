/**
 * Lümia Factures - Invoices settings screen.
 * Logo picker (wp.media), live number sample, color read-out, tab from the URL hash.
 * Data comes from PHP: lumiaFacturesAdmin.invoices (see Modules\Invoices\Module::get_admin_js_data()).
 */
(function () {
  "use strict";

  var data = (window.lumiaFacturesAdmin && window.lumiaFacturesAdmin.invoices) || {};

  function t(key) {
    return (window.lumiaFacturesAdmin && window.lumiaFacturesAdmin.i18n && window.lumiaFacturesAdmin.i18n[key]) || "";
  }

  function field(name) {
    return document.querySelector('[name="lumia_factures_module_settings[' + name.replace(".", "][") + ']"]');
  }

  function pad(value, length) {
    var text = String(value);
    while (text.length < length) text = "0" + text;
    return text;
  }

  /* ---------------- Number sample (mirrors NumberFormat::preview) ---------------- */

  /** Counter key of the current period (mirrors Sequences::key). */
  function counterKey(resetEnabled, period) {
    var base = data.type || "invoice";
    var sample = data.sample || {};
    if (!resetEnabled) return base;
    return period === "monthly" ? base + "_" + sample.year + "_" + sample.month : base + "_" + sample.year;
  }

  function renderFormat(format, counter) {
    var sample = data.sample || {};
    var tags = data.tags || [];
    var invalid = false;

    var text = format.replace(/\{([a-z_]+)(?::([^}]*))?\}/g, function (match, tag, argument) {
      argument = argument || "";
      if (tags.indexOf(tag) === -1) {
        invalid = true;
        return "";
      }
      switch (tag) {
        case "number":
          var padding = parseInt(argument, 10);
          return padding > 0 ? pad(counter, padding) : String(counter);
        case "year":
          return argument === "2" ? String(sample.year).slice(-2) : String(sample.year);
        case "month":
          return sample.month;
        case "day":
          return sample.day;
        case "order_number":
          return sample.orderNumber;
        case "order_id":
          return sample.orderId;
        case "customer_id":
          return sample.customerId;
        default:
          return "";
      }
    });

    return { text: text, invalid: invalid };
  }

  function initSample() {
    var output = document.querySelector("[data-lumia-factures-sample]");
    var format = field("numbering.format");
    if (!output || !format) return;

    var start = field("numbering.start_number");
    var reset = document.querySelector('input[type="checkbox"][name="lumia_factures_module_settings[numbering][reset_enabled]"]');
    var period = field("numbering.reset_period");

    function update() {
      var counters = data.counters || {};
      var key = counterKey(reset ? reset.checked : true, period ? period.value : "yearly");
      var last = parseInt(counters[key], 10) || 0;
      var first = Math.max(1, parseInt(start && start.value, 10) || 1);
      var next = last > 0 ? last + 1 : first;
      var result = renderFormat(format.value, next);

      output.textContent = result.invalid || result.text === "" ? t("sampleInvalid") : result.text;
      output.classList.toggle("is-invalid", result.invalid || result.text === "");
    }

    [format, start, reset, period].forEach(function (input) {
      if (!input) return;
      input.addEventListener("input", update);
      input.addEventListener("change", update);
    });
    update();
  }

  /* ---------------- Logo picker ---------------- */

  function initLogo() {
    var root = document.querySelector("[data-lumia-factures-logo]");
    if (!root) return;

    var idInput = root.querySelector("[data-lumia-factures-logo-id]");
    var thumb = root.querySelector("[data-lumia-factures-logo-thumb]");
    var pick = root.querySelector("[data-lumia-factures-logo-pick]");
    var remove = root.querySelector("[data-lumia-factures-logo-remove]");
    var frame = null;

    function setLogo(id, url) {
      idInput.value = String(id || 0);
      thumb.textContent = "";
      if (url) {
        var img = document.createElement("img");
        img.src = url;
        img.alt = "";
        thumb.appendChild(img);
      } else {
        var none = document.createElement("span");
        none.textContent = thumb.getAttribute("data-lumia-factures-empty") || "";
        thumb.appendChild(none);
      }
      remove.hidden = !url;
      // wp.media sets no DOM event on the hidden input: tell the unsaved-changes tracker.
      idInput.dispatchEvent(new Event("change", { bubbles: true }));
    }

    pick.addEventListener("click", function () {
      if (!window.wp || !window.wp.media) return;

      if (!frame) {
        frame = window.wp.media({
          title: t("logoFrameTitle"),
          button: { text: t("logoFrameButton") },
          library: { type: "image" },
          multiple: false,
        });
        frame.on("select", function () {
          var attachment = frame.state().get("selection").first().toJSON();
          var sizes = attachment.sizes || {};
          var url = (sizes.medium && sizes.medium.url) || (sizes.full && sizes.full.url) || attachment.url;
          setLogo(attachment.id, url);
        });
      }
      frame.open();
    });

    remove.addEventListener("click", function () {
      setLogo(0, "");
    });
  }

  /* ---------------- Colors ---------------- */

  function initColors() {
    document.querySelectorAll('input[type="color"].lumia-factures-inv-color').forEach(function (input) {
      var hex = document.querySelector('[data-lumia-factures-hex-for="' + input.id + '"]');
      if (!hex) return;
      input.addEventListener("input", function () {
        hex.textContent = input.value;
      });
    });
  }

  /* ---------------- Counters ---------------- */

  /**
   * The "Raise" inputs sit inside the module form in the DOM but belong to standalone forms
   * (form attribute): typing there must not mark the settings form as unsaved.
   */
  function initCounters() {
    var table = document.querySelector("[data-lumia-factures-counters]");
    if (!table) return;
    ["input", "change"].forEach(function (type) {
      table.addEventListener(type, function (event) {
        event.stopPropagation();
      });
    });
  }

  /* ---------------- Tab from the URL hash ---------------- */

  function initHash() {
    var name = (window.location.hash || "").replace("#", "");
    if (name && window.lumiaFacturesTabs) {
      window.lumiaFacturesTabs.activate("invoices", name);
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    initSample();
    initLogo();
    initColors();
    initCounters();
    initHash();
  });
})();
