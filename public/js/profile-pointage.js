/**
 * Pointage — profil public : reconnaissance silencieuse (arcc_emp_access_token + verify-identity).
 */
/* global window, document, navigator, crypto */

const EMP_AUTH_LS_KEY = "arcc_emp_access_token";

function readEmpAuthTokenForOrder(orderId) {
  if (orderId == null || orderId === "") return null;
  try {
    const raw = localStorage.getItem(EMP_AUTH_LS_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (parsed && typeof parsed === "object" && parsed.byOrder) {
      const t = parsed.byOrder[String(orderId)];
      return typeof t === "string" && t.length > 0 ? t : null;
    }
    return null;
  } catch {
    return null;
  }
}

async function sha256Hex(input) {
  const buf = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(input));
  return Array.from(new Uint8Array(buf))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}

function hashToUuid(hex32) {
  const h = hex32.replace(/[^a-f0-9]/gi, "").slice(0, 32).padEnd(32, "0");
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20, 32)}`;
}

function getWebGLParts() {
  try {
    const canvas = document.createElement("canvas");
    const gl = canvas.getContext("webgl") || canvas.getContext("experimental-webgl");
    if (!gl) return { vendor: "", renderer: "" };
    const dbg = gl.getExtension("WEBGL_debug_renderer_info");
    if (!dbg) return { vendor: "webgl", renderer: "unknown" };
    return {
      vendor: String(gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL) || ""),
      renderer: String(gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL) || ""),
    };
  } catch {
    return { vendor: "", renderer: "" };
  }
}

function stableCanvasToken() {
  try {
    const c = document.createElement("canvas");
    c.width = 240;
    c.height = 48;
    const ctx = c.getContext("2d");
    if (!ctx) return "";
    ctx.textBaseline = "alphabetic";
    ctx.fillStyle = "#424242";
    ctx.fillRect(8, 8, 100, 24);
    ctx.fillStyle = "#1e88e5";
    ctx.font = "14px Arial,Helvetica,sans-serif";
    ctx.fillText("DigiCard device id", 12, 22);
    const g = ctx.createLinearGradient(0, 0, 120, 0);
    g.addColorStop(0, "#ff0000");
    g.addColorStop(1, "#0000ff");
    ctx.fillStyle = g;
    ctx.fillRect(10, 28, 80, 6);
    return c.toDataURL().slice(-160);
  } catch {
    return "";
  }
}

function estimateIosFromScreen() {
  const w = Math.min(screen.width, screen.height);
  const h = Math.max(screen.width, screen.height);
  const dpr = window.devicePixelRatio || 1;
  const key = `${w}x${h}@${dpr}`;
  const map = {
    "375x667@2": "iPhone (6/7/8)",
    "414x736@3": "iPhone Plus (6-8)",
    "375x812@3": "iPhone X / XS / 11 Pro",
    "414x896@2": "iPhone XR / 11",
    "390x844@3": "iPhone 12 / 13 / 14",
    "428x926@3": "iPhone 12 Pro Max / 13 Pro Max / 14 Plus",
    "393x852@3": "iPhone 14 Pro / 15",
    "430x932@3": "iPhone 14 Pro Max / 15 Plus",
    "402x874@3": "iPhone 16",
    "440x956@3": "iPhone 16 Pro Max",
    "768x1024@2": "iPad (classique)",
    "834x1194@2": "iPad Pro 11",
    "1024x1366@2": "iPad Pro 12.9",
  };
  return map[key] || `iPhone ou iPad (${w}x${h}, ${dpr}x)`;
}

async function getCommercialDeviceModel() {
  const ua = navigator.userAgent || "";
  if (navigator.userAgentData?.getHighEntropyValues) {
    try {
      const ch = await navigator.userAgentData.getHighEntropyValues([
        "model",
        "platform",
        "platformVersion",
        "architecture",
      ]);
      const model = (ch.model || "").trim();
      const platform = (ch.platform || navigator.userAgentData.platform || "").trim();
      if (model && !/^generic/i.test(model)) {
        return `${platform} ${model}`.trim();
      }
      if (platform) {
        if (/iPhone|iPad|iOS/i.test(ua) || platform === "iOS") {
          return estimateIosFromScreen();
        }
        return `${platform} (appareil)`;
      }
    } catch {
      /* ignore */
    }
  }
  const androidMatch = ua.match(/Android[\s\d._]+;\s*([^)]+)\)/);
  if (androidMatch) {
    let rest = androidMatch[1].trim();
    if (rest.includes("Build/")) rest = rest.split("Build/")[0].trim();
    return rest || "Android";
  }
  if (/iPhone|iPad|iPod/i.test(ua)) {
    return estimateIosFromScreen();
  }
  return (navigator.platform || "Navigateur").trim();
}

async function computeFingerprintUuid() {
  const webgl = getWebGLParts();
  const canvasTok = stableCanvasToken();
  const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || "";
  const langs = (navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || ""]).join(",");
  const parts = [
    tz,
    langs,
    String(screen.width),
    String(screen.height),
    String(screen.colorDepth || 0),
    String(window.devicePixelRatio || 1),
    String(navigator.hardwareConcurrency || ""),
    String(navigator.deviceMemory || ""),
    String(navigator.maxTouchPoints ?? ""),
    webgl.vendor,
    webgl.renderer,
    canvasTok,
  ];
  const hex = await sha256Hex(parts.join("|"));
  return hashToUuid(hex);
}

async function computeDeviceIdentity() {
  const [uuid, model] = await Promise.all([computeFingerprintUuid(), getCommercialDeviceModel()]);
  return { uuid, model: model.slice(0, 250) };
}

const DEVICE_SEAL_LS_PREFIX = "digicard_device_seal_v1";

function readStoredSeal(orderId) {
  if (orderId == null || orderId === "") return null;
  try {
    const raw = localStorage.getItem(`${DEVICE_SEAL_LS_PREFIX}_${orderId}`);
    if (!raw) return null;
    const o = JSON.parse(raw);
    return o && typeof o.uuid === "string" ? o : null;
  } catch {
    return null;
  }
}

function writeStoredSeal(orderId, uuid, model) {
  if (orderId == null || orderId === "") return;
  try {
    localStorage.setItem(`${DEVICE_SEAL_LS_PREFIX}_${orderId}`, JSON.stringify({ uuid, model, t: Date.now() }));
  } catch {
    /* ignore */
  }
}

async function getOrCreateDeviceIdentity(orderId) {
  if (orderId == null || orderId === "") {
    return computeDeviceIdentity();
  }
  const stored = readStoredSeal(orderId);
  const modelFresh = (await getCommercialDeviceModel()).slice(0, 250);
  if (stored?.uuid && /^[0-9a-f-]{36}$/i.test(stored.uuid)) {
    return { uuid: stored.uuid, model: modelFresh };
  }
  const fresh = await computeDeviceIdentity();
  writeStoredSeal(orderId, fresh.uuid, fresh.model);
  return fresh;
}

function pointInPolygonRing(lng, lat, ring) {
  let inside = false;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const xi = ring[i][0];
    const yi = ring[i][1];
    const xj = ring[j][0];
    const yj = ring[j][1];
    const denom = yj - yi || 1e-9;
    const intersect = (yi > lat) !== (yj > lat) && lng < ((xj - xi) * (lat - yi)) / denom + xi;
    if (intersect) inside = !inside;
  }
  return inside;
}

function readPublicMeta() {
  const el = document.getElementById("pointage-public-meta");
  if (!el) return null;
  try {
    return JSON.parse(el.textContent || "{}");
  } catch {
    return null;
  }
}

function showModal(title, body, isError) {
  const m = document.getElementById("pointageFeedbackModal");
  const t = document.getElementById("pointageFeedbackTitle");
  const b = document.getElementById("pointageFeedbackBody");
  if (!m || !t || !b) {
    window.alert(title + "\n\n" + body);
    return;
  }
  t.textContent = title;
  b.textContent = body;
  b.className = isError ? "text-red-300 whitespace-pre-wrap text-sm" : "text-slate-200 whitespace-pre-wrap text-sm";
  m.classList.remove("hidden");
  m.classList.add("flex");
}

function closeModal() {
  const m = document.getElementById("pointageFeedbackModal");
  if (m) {
    m.classList.add("hidden");
    m.classList.remove("flex");
  }
}

function formatTimeFrFromIso(iso) {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  return d.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit", second: "2-digit" });
}

function applyPointageButtonUI(btn, dayStatus) {
  const cap = btn.querySelector(".pointage-btn-caption");
  if (dayStatus === "CHECKED_IN") {
    btn.title = "Pointer le Départ";
    btn.setAttribute("aria-label", "Pointer le Départ");
    if (cap) cap.textContent = "Pointer le Départ";
  } else {
    btn.title = "Pointer l’arrivée";
    btn.setAttribute("aria-label", "Pointer l’arrivée");
    if (cap) cap.textContent = "Pointer l’arrivée";
  }
}

function getPositionHighAccuracy() {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) {
      reject(new Error("no_geo"));
      return;
    }
    navigator.geolocation.getCurrentPosition(resolve, reject, {
      enableHighAccuracy: true,
      timeout: 25000,
      maximumAge: 0,
    });
  });
}

function createPointageButton() {
  const btn = document.createElement("button");
  btn.type = "button";
  btn.id = "pointage-action-btn";
  btn.className =
    "social-icon pointage text-gray-400 relative cursor-pointer flex-col gap-0.5 inline-flex";
  btn.style.display = "none";
  btn.title = "Pointer l’arrivée";
  btn.setAttribute("aria-label", "Pointer l’arrivée");
  btn.innerHTML = `
    <svg fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" class="w-7 h-7 flex-shrink-0">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
    <span class="pointage-btn-caption text-[0.5rem] text-slate-500 text-center leading-tight max-w-[5.75rem] hidden sm:block select-none"></span>
    <span class="pointage-spinner hidden absolute inset-0 flex items-center justify-center bg-slate-900/60 rounded-md">
      <span class="w-5 h-5 border-2 border-amber-400 border-t-transparent rounded-full animate-spin"></span>
    </span>
    <span class="absolute -top-1 -right-1 w-3 h-3 bg-amber-400 rounded-full animate-pulse"></span>
  `;
  return btn;
}

async function runSilentPointage() {
  const meta = readPublicMeta();
  if (!meta || !meta.eligible || !meta.username || !meta.order_id) {
    return;
  }

  const empToken = readEmpAuthTokenForOrder(meta.order_id);
  if (!empToken) {
    return;
  }

  if (!window.crypto?.subtle) {
    return;
  }

  const anchor = document.getElementById("pointage-employee-anchor");
  if (!anchor || !anchor.parentNode) {
    return;
  }

  const apiRoot = (meta.api_base || "").replace(/\/$/, "");
  const urlVerifyIdentity = `${apiRoot}/api/public/pointage/verify-identity`;
  const urlCheckIn = `${apiRoot}/api/public/pointage/check-in`;
  const urlCheckOut = `${apiRoot}/api/public/pointage/check-out`;

  let session = null;
  let devicePayload = null;

  try {
    const { uuid, model } = await getOrCreateDeviceIdentity(meta.order_id);
    writeStoredSeal(meta.order_id, uuid, model);
    devicePayload = {
      username: meta.username,
      order_id: meta.order_id,
      emp_auth_token: empToken,
      device_uuid: uuid,
      device_model: model,
    };

    const res = await fetch(urlVerifyIdentity, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify(devicePayload),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      if (data.code === "outside_schedule" || data.code === "pointage_unavailable") {
        return;
      }
      if (data.code === "device_mismatch" || data.code === "invalid_enrollment") {
        showModal(
          "Pointage",
          "Cet appareil ne correspond pas à votre enregistrement ou le jeton n’est plus valide. Ouvrez votre espace personnel sur ce téléphone pour mettre à jour la liaison.",
          true,
        );
      }
      return;
    }
    session = data;
    if (!session.day_status) session.day_status = "NOT_STARTED";
  } catch (e) {
    console.warn("[pointage] verify-identity failed", e);
    return;
  }

  const btn = createPointageButton();
  anchor.replaceWith(btn);

  const spinner = btn.querySelector(".pointage-spinner");

  const refreshVisibility = () => {
    if (!session) {
      btn.style.display = "none";
      return;
    }
    if (session.day_status === "COMPLETED") {
      btn.style.display = "none";
      return;
    }
    btn.style.display = "inline-flex";
    applyPointageButtonUI(btn, session.day_status);
  };

  refreshVisibility();
  setInterval(refreshVisibility, 60 * 1000);

  btn.addEventListener("click", async () => {
    if (btn.dataset.loading === "1") return;
    if (!session?.polygon) return;

    const ring = session.polygon.coordinates?.[0];
    if (!ring || ring.length < 4) {
      showModal("Pointage", "Configuration de zone invalide.", true);
      return;
    }

    const isDeparture = session.day_status === "CHECKED_IN" || session.can_check_out === true;
    if (isDeparture) {
      const ok = window.confirm("Confirmez-vous votre fin de service pour aujourd'hui ?");
      if (!ok) return;
    }

    btn.dataset.loading = "1";
    if (spinner) spinner.classList.remove("hidden");

    try {
      let pos;
      try {
        pos = await getPositionHighAccuracy();
      } catch {
        showModal("Pointage", "Impossible d'obtenir votre position. Vérifiez les autorisations GPS.", true);
        return;
      }

      const lat = pos.coords.latitude;
      const lng = pos.coords.longitude;
      const inside = pointInPolygonRing(lng, lat, ring);
      if (!inside) {
        showModal("Pointage", "Vous devez être à l'intérieur de la zone de l'entreprise pour pointer.", true);
        return;
      }

      const apiBody = {
        username: devicePayload.username,
        device_uuid: devicePayload.device_uuid,
        device_model: devicePayload.device_model,
        order_id: devicePayload.order_id,
        access_token: null,
        short_code: null,
        latitude: lat,
        longitude: lng,
      };

      if (!isDeparture) {
        const res = await fetch(urlCheckIn, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify(apiBody),
        });
        const cdata = await res.json().catch(() => ({}));
        if (!res.ok || !cdata.ok) {
          let msg =
            cdata.code === "already_checked_in"
              ? "Vous avez déjà pointé votre arrivée aujourd'hui."
              : cdata.message || "Impossible d'enregistrer l'arrivée.";
          if (cdata.code === "outside_polygon") {
            msg = "Vous devez être à l'intérieur de la zone de l'entreprise pour pointer.";
          }
          if (cdata.code === "outside_schedule") {
            msg = "Le pointage n'est pas autorisé en dehors des plages horaires définies pour votre groupe.";
          }
          showModal("Pointage", msg, true);
          return;
        }
        session.day_status = "CHECKED_IN";
        session.can_check_in = false;
        session.can_check_out = true;
        const now = new Date();
        const dateStr = now.toLocaleDateString("fr-FR", {
          weekday: "long",
          year: "numeric",
          month: "long",
          day: "numeric",
        });
        const timeStr =
          formatTimeFrFromIso(cdata.check_in_time) ||
          now.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit", second: "2-digit" });
        const posStr = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
        showModal(
          "Pointage",
          `Émargement effectué avec succès !\n\nHeure : ${timeStr}\nDate : ${dateStr}\nPosition : ${posStr}`,
          false,
        );
        applyPointageButtonUI(btn, session.day_status);
      } else {
        const res = await fetch(urlCheckOut, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify(apiBody),
        });
        const cdata = await res.json().catch(() => ({}));
        if (!res.ok || !cdata.ok) {
          let msg = cdata.message || "Impossible d'enregistrer le départ.";
          if (cdata.code === "no_check_in_today") msg = "Aucune arrivée enregistrée pour aujourd'hui.";
          if (cdata.code === "already_checked_out") msg = "Le départ est déjà enregistré pour aujourd'hui.";
          if (cdata.code === "outside_polygon") msg = "Vous devez être à l'intérieur de la zone de l'entreprise pour pointer.";
          if (cdata.code === "outside_schedule") {
            msg = "Le pointage n'est pas autorisé en dehors des plages horaires définies pour votre groupe.";
          }
          showModal("Pointage", msg, true);
          return;
        }
        session.day_status = "COMPLETED";
        session.can_check_in = false;
        session.can_check_out = false;
        const outTime = formatTimeFrFromIso(cdata.check_out_time);
        showModal("Pointage", `Départ enregistré avec succès à ${outTime}. Bonne soirée !`, false);
        refreshVisibility();
      }
    } catch (e) {
      console.warn("[pointage] action failed", e);
      showModal("Pointage", "Une erreur est survenue. Réessayez.", true);
    } finally {
      btn.dataset.loading = "0";
      if (spinner) spinner.classList.add("hidden");
    }
  });
}

function scheduleRun() {
  const go = () => runSilentPointage().catch((e) => console.warn("[pointage]", e));
  if (typeof window.requestIdleCallback === "function") {
    window.requestIdleCallback(() => go(), { timeout: 2500 });
  } else {
    window.setTimeout(go, 0);
  }
}

document.addEventListener("DOMContentLoaded", scheduleRun);

window.closePointageFeedbackModal = closeModal;
