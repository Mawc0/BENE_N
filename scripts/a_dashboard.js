// ── Sidebar expand/collapse ──
document.addEventListener("DOMContentLoaded", function () {
  const sidebar = document.getElementById("sidebar");
  const topbar = document.getElementById("topbar");
  const main = document.getElementById("mainContent");
  const hamburger = document.getElementById("hamburger");

  function applyExpanded(expanded) {
    if (expanded) {
      sidebar.classList.add("expanded");
      topbar.style.left = "var(--sidebar-expanded)";
      main.style.marginLeft = "var(--sidebar-expanded)";
    } else {
      sidebar.classList.remove("expanded");
      topbar.style.left = "var(--sidebar-w)";
      main.style.marginLeft = "var(--sidebar-w)";
    }
  }

  if (localStorage.getItem("sidebarExpanded") === "true") applyExpanded(true);

  hamburger.addEventListener("click", () => {
    const isExpanded = !sidebar.classList.contains("expanded");
    applyExpanded(isExpanded);
    localStorage.setItem("sidebarExpanded", isExpanded);
  });
});

// ── Profile dropdown ──
function toggleProfileMenu() {
  const dropdown = document.getElementById("profileDropdown");
  dropdown.classList.toggle("show");
  document.addEventListener("click", function closeDD(e) {
    if (!document.querySelector(".profile-menu").contains(e.target)) {
      dropdown.classList.remove("show");
      document.removeEventListener("click", closeDD);
    }
  });
}

// ── Modals ──
function openLogoutModal() {
  document.getElementById("logoutModal").style.display = "block";
}
function closeLogoutModal() {
  document.getElementById("logoutModal").style.display = "none";
}

function openDeleteCategoryModal(id, name) {
  document.getElementById("deleteCategoryName").textContent = name;
  document.getElementById("confirmDeleteCategory").href =
    "dashboard.php?page=categories&delete_cat=" + id;
  document.getElementById("deleteCategoryModal").style.display = "block";
}
function closeDeleteCategoryModal() {
  document.getElementById("deleteCategoryModal").style.display = "none";
}

function openDeleteScheduleModal(id, name) {
  document.getElementById("deleteScheduleName").textContent = name;
  document.getElementById("confirmDeleteSchedule").href =
    "dashboard.php?page=schedules&delete_schedule=" + id;
  document.getElementById("deleteScheduleModal").style.display = "block";
}
function closeDeleteScheduleModal() {
  document.getElementById("deleteScheduleModal").style.display = "none";
}

function openEditScheduleModal(id, name, type, time, day) {
  document.getElementById("editScheduleId").value = id;
  document.getElementById("editScheduleName").value = name;
  document.getElementById("editScheduleType").value = type;
  document.getElementById("editScheduleTime").value = time;
  document.getElementById("editScheduleDay").value = day || "";
  document.getElementById("editScheduleModal").style.display = "block";
  updateDayInput(
    document.getElementById("editScheduleType"),
    document.getElementById("editScheduleDay"),
    document.getElementById("editDayField"),
    document.getElementById("editDayLabel"),
  );
}
function closeEditScheduleModal() {
  document.getElementById("editScheduleModal").style.display = "none";
}

window.onclick = function (e) {
  [
    "logoutModal",
    "deleteCategoryModal",
    "deleteScheduleModal",
    "editScheduleModal",
  ].forEach((id) => {
    const m = document.getElementById(id);
    if (m && e.target === m) m.style.display = "none";
  });
};

// ── Schedule helpers ──
function toggleSchedule(id) {
  window.location.href = `dashboard.php?page=schedules&toggle_schedule=${id}`;
}

function updateDayInput(sel, inp, container, lbl) {
  const t = sel.value;
  inp.max = t === "weekly" ? 7 : 31;
  lbl.textContent =
    t === "weekly" ? "Day of week (1–7)" : "Day of month (1–31)";
  container.style.display = t === "daily" ? "none" : "block";
}

document.addEventListener("DOMContentLoaded", function () {
  const ct = document.getElementById("schedule_type");
  const ci = document.getElementById("schedule_day");
  const cc = document.getElementById("dayField");
  const cl = document.getElementById("dayLabel");
  if (ct && ci && cc && cl) {
    ct.addEventListener("change", () => updateDayInput(ct, ci, cc, cl));
    updateDayInput(ct, ci, cc, cl);
  }
  const et = document.getElementById("editScheduleType");
  const ei = document.getElementById("editScheduleDay");
  const ec = document.getElementById("editDayField");
  const el = document.getElementById("editDayLabel");
  if (et && ei && ec && el)
    et.addEventListener("change", () => updateDayInput(et, ei, ec, el));
});

// ── Medicine API (auto-refresh every 5 min) ──
function fetchMedicinesAPI() {
  fetch("api/medicines.php")
    .then((r) => r.json())
    .then((data) => updateMedicineTable(data))
    .catch(() => {});
}
function updateMedicineTable(data) {
  const tb = document.querySelector("#medicines-table tbody");
  if (!tb) return;
  tb.innerHTML = "";
  data.forEach((med) => {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td>${med.id}</td><td>${med.name}</td><td>${med.type}</td><td>${med.quantity}</td><td>${med.expired_date}</td><td class="${getStatusClass(med)}">${getStatusText(med)}</td>`;
    tb.appendChild(tr);
  });
}
function getStatusClass(med) {
  if (new Date(med.expired_date) <= new Date()) return "badge-expired";
  if (med.quantity <= 20) return "badge-low";
  if (new Date(med.expired_date) <= new Date(Date.now() + 7 * 86400000))
    return "badge-low";
  return "badge-good";
}
function getStatusText(med) {
  if (new Date(med.expired_date) <= new Date()) return "Expired";
  if (med.quantity <= 20) return "Low Stock";
  if (new Date(med.expired_date) <= new Date(Date.now() + 7 * 86400000))
    return "Expiring Soon";
  return "Good";
}
setInterval(fetchMedicinesAPI, 300000);
