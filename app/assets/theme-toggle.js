(() => {
  const STORAGE_KEY = "certif-theme";
  const root = document.documentElement;
  const currentYear = new Date().getFullYear();

  const applyTheme = (theme) => {
    if (theme === "dark") {
      root.setAttribute("data-theme", "dark");
    } else {
      root.removeAttribute("data-theme");
    }
  };

  const savedTheme = localStorage.getItem(STORAGE_KEY);
  applyTheme(savedTheme);

  const button = document.createElement("button");
  button.type = "button";
  button.className = "theme-toggle";
  button.setAttribute("aria-label", "Changer le theme");
  button.innerHTML = `
    <svg class="icon-moon" viewBox="0 0 24 24" aria-hidden="true">
      <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 1 0 9.8 9.8z"></path>
    </svg>
    <svg class="icon-sun" viewBox="0 0 24 24" aria-hidden="true">
      <circle cx="12" cy="12" r="4"></circle>
      <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
    </svg>
  `;

  button.addEventListener("click", () => {
    const isDark = root.getAttribute("data-theme") === "dark";
    if (isDark) {
      root.removeAttribute("data-theme");
      localStorage.setItem(STORAGE_KEY, "light");
    } else {
      root.setAttribute("data-theme", "dark");
      localStorage.setItem(STORAGE_KEY, "dark");
    }
  });

  const mountButton = () => {
    document.body.appendChild(button);
  };

  const mountFooter = async () => {
    if (document.querySelector(".app-version-footer")) {
      return;
    }

    const footer = document.createElement("div");
    footer.className = "app-version-footer";
    footer.textContent = `Probance Certif Tool - Probance ${currentYear}`;
    document.body.appendChild(footer);

    try {
      const response = await fetch("/version.php", {
        headers: {
          Accept: "application/json"
        }
      });
      if (!response.ok) {
        return;
      }

      const data = await response.json();
      if (data && typeof data.app_version === "string" && data.app_version.trim() !== "") {
        footer.textContent = `Probance Certif Tool - V ${data.app_version.trim()} - Probance ${currentYear}`;
      }
    } catch (error) {
      // Keep the fallback footer text if the endpoint is unavailable.
    }
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      mountButton();
      mountFooter();
    });
  } else {
    mountButton();
    mountFooter();
  }
})();
