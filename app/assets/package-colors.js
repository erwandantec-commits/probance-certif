(function () {
  var PACKAGE_COLORS = {
    GREEN: "#16a34a",
    BLUE: "#2563eb",
    RED: "#dc2626",
    BLACK: "#111827",
    SILVER: "#64748b",
    GOLD: "#d4af37"
  };

  function normalizePackageName(label) {
    var text = (label || "").trim();
    var paren = text.indexOf("(");
    if (paren > 0) {
      text = text.slice(0, paren).trim();
    }
    return text.toUpperCase();
  }

  function applySelectColor(select) {
    if (!select || !select.options || select.selectedIndex < 0) return;
    var option = select.options[select.selectedIndex];
    var name = normalizePackageName(option.textContent || option.innerText || "");
    var color = PACKAGE_COLORS[name] || "#111827";
    select.style.color = color;
    select.style.fontWeight = "700";
  }

  function initPackageSelectColors(root) {
    var scope = root || document;
    var selects = scope.querySelectorAll("select[name='package_id'], select#package_id");
    selects.forEach(function (select) {
      applySelectColor(select);
      select.addEventListener("change", function () {
        applySelectColor(select);
      });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      initPackageSelectColors(document);
    });
  } else {
    initPackageSelectColors(document);
  }
})();
