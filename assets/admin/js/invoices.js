/**
 * Lümia Factures - Invoices settings screen.
 * Logo picker (wp.media), live number sample, color fields and themes, live PDF preview (pdf.js),
 * tab from the URL hash.
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

  /** Sets a form field and tells the listeners (unsaved changes, preview, themes). */
  function setValue(input, value) {
    if (input.value === value) return;
    input.value = value;
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  /** "#abc", "abc" or "#aabbcc" to "#aabbcc"; "" when it is not a color. */
  function normalizeHex(text) {
    var value = String(text || "").trim().toLowerCase();
    if (value && value.charAt(0) !== "#") value = "#" + value;
    if (/^#[0-9a-f]{3}$/.test(value)) {
      value = "#" + value.charAt(1) + value.charAt(1) + value.charAt(2) + value.charAt(2) + value.charAt(3) + value.charAt(3);
    }
    return /^#[0-9a-f]{6}$/.test(value) ? value : "";
  }

  /**
   * Each swatch (the posted value) has a hex text box without a name: typing a valid code sets the
   * swatch, picking a color rewrites the code.
   */
  function initColors() {
    document.querySelectorAll("[data-lumia-factures-hex-for]").forEach(function (hex) {
      var input = document.getElementById(hex.getAttribute("data-lumia-factures-hex-for"));
      if (!input) return;

      input.addEventListener("input", function () {
        // Not while the code is being typed: "#abc" would be expanded under the cursor.
        if (document.activeElement === hex) return;
        hex.value = input.value;
        hex.classList.remove("is-invalid");
      });

      hex.addEventListener("input", function () {
        var value = normalizeHex(hex.value);
        hex.classList.toggle("is-invalid", value === "");
        if (value) setValue(input, value);
      });

      hex.addEventListener("blur", function () {
        hex.value = input.value;
        hex.classList.remove("is-invalid");
      });

      // Enter would submit (save) the whole form.
      hex.addEventListener("keydown", function (event) {
        if (event.key === "Enter") event.preventDefault();
      });
    });
  }

  /**
   * Theme buttons fill the color fields; the button whose colors all match the form is pressed.
   * The palettes come from Admin\Themes (data attribute), keyed like the design settings.
   */
  function initThemes() {
    var box = document.querySelector("[data-lumia-factures-themes]");
    if (!box) return;
    var buttons = Array.prototype.slice.call(box.querySelectorAll("[data-lumia-factures-theme]"));

    function palette(button) {
      try {
        return JSON.parse(button.getAttribute("data-lumia-factures-theme")) || {};
      } catch (e) {
        return {};
      }
    }

    function sync() {
      buttons.forEach(function (button) {
        var colors = palette(button);
        var same = Object.keys(colors).every(function (key) {
          var input = field("design." + key);
          return !input || input.value.toLowerCase() === String(colors[key]).toLowerCase();
        });
        button.setAttribute("aria-pressed", same ? "true" : "false");
      });
    }

    buttons.forEach(function (button) {
      button.addEventListener("click", function () {
        var colors = palette(button);
        Object.keys(colors).forEach(function (key) {
          var input = field("design." + key);
          if (input) setValue(input, String(colors[key]));
        });
        sync();
      });
    });

    document.addEventListener("input", function (event) {
      if (event.target && event.target.type === "color") sync();
    });
    sync();
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

    // Raising is irreversible: confirm before the standalone form is submitted.
    document.querySelectorAll(".lumia-factures-inv-raise-form").forEach(function (form) {
      form.addEventListener("submit", function (event) {
        var input = document.querySelector('[form="' + form.id + '"][name="value"]');
        if (!input || !window.lumiaFacturesModal) return;
        event.preventDefault();

        var message = t("raiseMessage")
          .replace("%1$s", input.getAttribute("data-lumia-factures-raise-key"))
          .replace("%2$s", input.getAttribute("data-lumia-factures-raise-current"))
          .replace("%3$s", input.value);

        window.lumiaFacturesModal.open({
          title: t("raiseTitle"),
          message: message,
          confirmLabel: t("raiseConfirm"),
          danger: true,
          onConfirm: function () {
            form.submit();
          },
        });
      });
    });
  }

  /* ---------------- Live preview ---------------- */

  /**
   * Renders the unsaved form against a real order (Admin\Preview, nothing is saved or numbered).
   * The whole module form is posted as FormData, like a save. Requests are debounced, and only the
   * latest response is shown.
   *
   * The pages are drawn by pdf.js (assets/vendor/pdfjs, loaded on first use) as white sheets fitted
   * to the pane width, times the zoom. If pdf.js cannot load or read the file, the browser's own
   * PDF viewer shows it instead. The pane only shows next to the tabs of
   * `data-lumia-factures-preview-tabs`; a change made elsewhere is rendered when it shows again.
   */
  function initPreview() {
    var root = document.querySelector("[data-lumia-factures-preview]");
    var form = document.getElementById("lumia-factures-module-form");
    var admin = window.lumiaFacturesAdmin || {};
    if (!root || !form || !admin.ajaxUrl) return;

    var stage = root.querySelector("[data-lumia-factures-preview-stage]");
    var pagesBox = root.querySelector("[data-lumia-factures-preview-pages]");
    var errorBox = root.querySelector("[data-lumia-factures-preview-error]");
    var orderInput = root.querySelector("[data-lumia-factures-preview-order]");
    var refresh = root.querySelector("[data-lumia-factures-preview-refresh]");
    var zoomValue = root.querySelector('[data-lumia-factures-preview-zoom="0"]');
    var tabs = (root.getAttribute("data-lumia-factures-preview-tabs") || "").split(" ");

    var ZOOM_STEP = 1.25;
    var ZOOM_MIN = 0.5;
    var ZOOM_MAX = 3;
    // pdf.js scale 1 draws one CSS pixel per PDF point: 100 % is the printed size at 96 dpi.
    var CSS_PER_POINT = 96 / 72;

    var counter = 0; // Latest request.
    var drawing = 0; // Latest drawing.
    var timer = null;
    var resizeTimer = null;
    var stale = true; // The form changed since the last render (or nothing rendered yet).
    var zoom = 1; // Times the fitted width.
    var pdfDoc = null;
    var pdfTask = null; // Loading task of pdfDoc.
    var pdfjs = null;
    var blobUrl = "";
    var needsDraw = false; // A drawing was skipped while the pane had no width (hidden).

    function showError(message) {
      errorBox.textContent = message;
      errorBox.hidden = !message;
    }

    function setBusy(busy) {
      refresh.disabled = busy;
      root.setAttribute("aria-busy", busy ? "true" : "false");
    }

    function visible() {
      var active = document.querySelector('[data-lumia-tabs="invoices"] [data-lumia-tab].is-active');
      return !!active && tabs.indexOf(active.getAttribute("data-lumia-tab")) !== -1;
    }

    function loadPdfjs() {
      if (!pdfjs) {
        var urls = data.pdfjs || {};
        pdfjs = urls.lib
          ? import(urls.lib).then(function (lib) {
              lib.GlobalWorkerOptions.workerSrc = urls.worker;
              return lib;
            })
          : Promise.reject(new Error("pdf.js is not configured"));
        // A failed load (network hiccup) is tried again on the next render.
        pdfjs.catch(function () {
          pdfjs = null;
        });
      }
      return pdfjs;
    }

    function revokeBlob() {
      if (blobUrl) URL.revokeObjectURL(blobUrl);
      blobUrl = "";
    }

    /** Forgets the pdf.js document, so a zoom or a resize never redraws an older PDF. */
    function dropDocument() {
      if (pdfTask) pdfTask.destroy();
      pdfTask = null;
      pdfDoc = null;
    }

    /** Fallback: the browser's own PDF viewer in a frame. */
    function showFrame(bytes) {
      dropDocument();
      var previous = blobUrl;
      blobUrl = URL.createObjectURL(new Blob([bytes], { type: "application/pdf" }));
      var frame = document.createElement("iframe");
      frame.className = "lumia-factures-inv-preview__frame";
      frame.title = root.getAttribute("aria-label") || "";
      frame.src = blobUrl;
      pagesBox.replaceChildren(frame);
      zoomValue.parentNode.hidden = true;
      if (previous) URL.revokeObjectURL(previous);
    }

    /** Draws every page off screen, then swaps them in at once (no flicker while typing). */
    function draw() {
      if (!pdfDoc) return Promise.resolve();
      // Hidden pane (another tab): no width to fit. Drawn when it shows again.
      if (stage.clientWidth === 0) {
        needsDraw = true;
        return Promise.resolve();
      }
      needsDraw = false;
      var current = ++drawing;
      var doc = pdfDoc;
      var ratio = window.devicePixelRatio || 1;
      var canvases = [];

      return doc.getPage(1).then(function (first) {
        var style = window.getComputedStyle(stage);
        var room = stage.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
        var scale = Math.max(0.1, room / first.getViewport({ scale: 1 }).width) * zoom;
        zoomValue.textContent = Math.round((scale / CSS_PER_POINT) * 100) + " %";

        var chain = Promise.resolve();
        for (var number = 1; number <= doc.numPages; number++) {
          chain = chain.then(drawPage.bind(null, doc, number, scale, ratio, canvases, current));
        }
        return chain;
      }).then(function () {
        if (current !== drawing) return;
        pagesBox.replaceChildren.apply(pagesBox, canvases);
        revokeBlob(); // The fallback frame, if any, is gone.
      });
    }

    /** Redraw from a zoom or a resize: a document destroyed meanwhile is not an error. */
    function redraw() {
      draw().catch(function () {});
    }

    function drawPage(doc, number, scale, ratio, canvases, current) {
      if (current !== drawing) return null;
      return doc.getPage(number).then(function (page) {
        var viewport = page.getViewport({ scale: scale * ratio });
        var canvas = document.createElement("canvas");
        canvas.className = "lumia-factures-inv-preview__page";
        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        canvas.style.width = Math.floor(viewport.width / ratio) + "px";
        canvas.style.height = Math.floor(viewport.height / ratio) + "px";
        canvases.push(canvas);
        return page.render({ canvas: canvas, viewport: viewport }).promise;
      });
    }

    function show(base64, current) {
      var binary = window.atob(base64);
      var bytes = new Uint8Array(binary.length);
      for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);

      var task = null;

      return loadPdfjs()
        .then(function (lib) {
          // pdf.js takes the buffer over: keep a copy for the fallback.
          task = lib.getDocument({ data: bytes.slice(), enableXfa: false });
          return task.promise;
        })
        .then(function (doc) {
          // The loading task owns the document and its worker resources: it is what gets destroyed.
          if (current !== counter) {
            task.destroy();
            return null;
          }
          var previous = pdfTask;
          pdfTask = task;
          pdfDoc = doc;
          if (previous) previous.destroy();
          zoomValue.parentNode.hidden = false;
          return draw();
        })
        .catch(function (error) {
          if (current !== counter) {
            if (task && task !== pdfTask) task.destroy();
            return;
          }
          if (window.console) window.console.warn("Lumia Factures preview: pdf.js failed, the browser viewer is used.", error);
          if (task && task !== pdfTask) task.destroy();
          showFrame(bytes);
        });
    }

    function request() {
      window.clearTimeout(timer);
      var current = ++counter;
      var body = new FormData(form);
      // The form carries its own save action: override it.
      body.set("action", "lumia_factures_invoices_preview");
      body.set("nonce", admin.nonce || "");
      body.set("order_id", orderInput.value || "0");

      stale = false;
      setBusy(true);

      window
        .fetch(admin.ajaxUrl, { method: "POST", credentials: "same-origin", body: body })
        .then(function (response) {
          return response.json();
        })
        .then(function (json) {
          if (current !== counter) return null; // A newer request is on its way.
          if (json && json.success && json.data && json.data.pdf) {
            showError("");
            return show(json.data.pdf, current);
          }
          showError((json && json.data && json.data.message) || t("previewFailed"));
          stale = true; // Tried again when the pane shows again.
          return null;
        })
        .catch(function () {
          if (current !== counter) return;
          showError(t("previewFailed"));
          stale = true;
        })
        .then(function () {
          if (current === counter) setBusy(false);
        });
    }

    function schedule() {
      window.clearTimeout(timer);
      timer = window.setTimeout(request, 600);
    }

    /** Shows the pane next to its tabs, and renders what changed while it was hidden. */
    function sync() {
      var on = visible();
      root.hidden = !on;
      if (!on) return;
      if (stale) request();
      else if (needsDraw) redraw();
    }

    ["input", "change"].forEach(function (type) {
      form.addEventListener(type, function (event) {
        // The preview's own fields are not settings.
        if (root.contains(event.target)) return;
        stale = true;
        if (!root.hidden) schedule();
      });
    });

    refresh.addEventListener("click", request);
    // One request per order value: Enter, then the change fired on blur, ask only once.
    var requestedOrder = orderInput.value;
    function requestOrder() {
      if (orderInput.value === requestedOrder) return;
      requestedOrder = orderInput.value;
      request();
    }
    orderInput.addEventListener("change", requestOrder);
    orderInput.addEventListener("keydown", function (event) {
      // Enter would submit (save) the whole form.
      if (event.key !== "Enter") return;
      event.preventDefault();
      requestOrder();
    });

    root.querySelectorAll("[data-lumia-factures-preview-zoom]").forEach(function (button) {
      button.addEventListener("click", function () {
        var step = parseInt(button.getAttribute("data-lumia-factures-preview-zoom"), 10);
        zoom = step === 0 ? 1 : Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, step > 0 ? zoom * ZOOM_STEP : zoom / ZOOM_STEP));
        redraw();
      });
    });

    if (window.ResizeObserver) {
      var lastWidth = 0;
      new window.ResizeObserver(function () {
        var width = stage.clientWidth;
        if (width === lastWidth) return;
        lastWidth = width; // 0 while hidden: showing again counts as a change.
        if (!pdfDoc || width === 0) return;
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(redraw, 150);
      }).observe(stage);
    }

    document.addEventListener("lumia-factures:tab", function (event) {
      if (event.detail && event.detail.group === "invoices") sync();
    });
    sync();
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
    initThemes();
    initCounters();
    initHash();
    initPreview();
  });
})();
