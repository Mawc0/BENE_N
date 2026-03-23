// ── Unit helpers ──
const LIQUID_TYPES = ['Injection', 'Antiseptic', 'Syrup', 'Solution', 'Drops', 'Suspension'];
 
function getMedUnit(type) {
  return LIQUID_TYPES.includes(type) ? 'mL' : 'pcs';
}
 
function updateAddMedUnit(selectEl) {
  const unit = getMedUnit(selectEl.value);
  const badge = document.getElementById('add-med-unit-badge');
  const qtyInput = document.getElementById('add-med-qty');
  if (!badge) return;
  if (unit === 'mL') {
    badge.style.display = 'inline';
    badge.innerHTML = '💧 mL';
    badge.style.background = '#e0f2fe';
    badge.style.color = '#0369a1';
    if (qtyInput) qtyInput.placeholder = 'e.g. 500 mL';
  } else {
    badge.style.display = 'inline';
    badge.innerHTML = '💊 pcs';
    badge.style.background = '#fef2f2';
    badge.style.color = '#b91c1c';
    if (qtyInput) qtyInput.placeholder = 'e.g. 100 pcs';
  }
}
// ── Video hover popup ──
const popup = document.getElementById("videoPopup");
const frame = document.getElementById("popupFrame");
let hideTimeout = null;

document.querySelectorAll(".video-hover").forEach((item) => {
  item.addEventListener("mouseenter", (e) => {
    clearTimeout(hideTimeout);
    const videoURL = e.target.getAttribute("data-video");
    frame.src = videoURL + "?autoplay=1&mute=1";
    popup.style.display = "block";
    popup.style.opacity = "1";
    const rect = e.target.getBoundingClientRect();
    popup.style.top = window.scrollY + rect.bottom + 5 + "px";
    popup.style.left = rect.left + "px";
  });

  item.addEventListener("mouseleave", () => {
    hideTimeout = setTimeout(() => {
      popup.style.display = "none";
      frame.src = "";
    }, 400);
  });
});

popup.addEventListener("mouseenter", () => {
  clearTimeout(hideTimeout);
});

popup.addEventListener("mouseleave", () => {
  hideTimeout = setTimeout(() => {
    popup.style.display = "none";
    frame.src = "";
  }, 400);
});

// ── Sidebar & navigation ──
const sidebar = document.getElementById("sidebar");
const topbar = document.getElementById("topbar");
const mainContent = document.getElementById("main-content");
const hamburger = document.getElementById("hamburger");
const buttons = {
  dashboard: document.getElementById("btn-dashboard"),
  inventory: document.getElementById("btn-inventory"),
  expiration: document.getElementById("btn-expiration"),
  donate: document.getElementById("btn-donate"),
  donationHistory: document.getElementById("btn-donation-history"),
};
const contents = {
  dashboard: document.getElementById("content-dashboard"),
  add: document.getElementById("content-add"),
  inventory: document.getElementById("content-inventory"),
  expiration: document.getElementById("content-expiration"),
  donate: document.getElementById("content-donate"),
  donationHistory: document.getElementById("content-donation-history"),
};

function applyExpanded(expanded) {
  if (expanded) {
    sidebar.classList.add("expanded");
    if (topbar) topbar.style.left = "var(--sidebar-exp)";
    if (mainContent) mainContent.style.marginLeft = "var(--sidebar-exp)";
  } else {
    sidebar.classList.remove("expanded");
    if (topbar) topbar.style.left = "var(--sidebar-w)";
    if (mainContent) mainContent.style.marginLeft = "var(--sidebar-w)";
  }
}
if (localStorage.getItem("sidebarExpanded") === "true") applyExpanded(true);
hamburger.addEventListener("click", () => {
  const exp = !sidebar.classList.contains("expanded");
  applyExpanded(exp);
  localStorage.setItem("sidebarExpanded", exp);
});

const sectionTitles = {
  dashboard: "Dashboard",
  inventory: "Inventory",
  expiration: "Expiration Tracker",
  donate: "Donate or Dispose",
  donationHistory: "My Requests",
};

function showSection(name) {
  Object.keys(contents).forEach((key) => {
    if (contents[key]) contents[key].classList.remove("active");
  });
  Object.keys(buttons).forEach((key) => {
    if (buttons[key]) buttons[key].classList.remove("active");
  });

  if (contents[name]) {
    contents[name].classList.add("active");
    if (buttons[name]) buttons[name].classList.add("active");
    if (name === "history") loadHistoryCategories();
  }

  const titleEl = document.getElementById("topbar-title");
  if (titleEl && sectionTitles[name]) titleEl.textContent = sectionTitles[name];

  window.scrollTo({ top: 0, behavior: "smooth" });
}

// ── Inventory filter + pagination ──
let invActiveCategory = "all";
const INV_PAGE_SIZE = 6;
let invCurrentPage = 1;

function filterInventory(category, btn) {
  invActiveCategory = category;
  invCurrentPage = 1;
  document
    .querySelectorAll(".inv-pill")
    .forEach((p) => p.classList.remove("active"));
  if (btn) btn.classList.add("active");
  applyInventoryFilter();
}

function applyInventoryFilter() {
  const search = (
    document.getElementById("inventory-search")?.value || ""
  ).toLowerCase();
  const usePaging = invActiveCategory === "all" && !search;

  const allRows = [...document.querySelectorAll("#inventory-table tbody tr")];
  const matched = allRows.filter((row) => {
    const cat = row.dataset.category || "";
    const catMatch = invActiveCategory === "all" || cat === invActiveCategory;
    const nameMatch = !search || row.textContent.toLowerCase().includes(search);
    return catMatch && nameMatch;
  });

  allRows.forEach((r) => (r.style.display = "none"));

  if (usePaging) {
    const total = matched.length;
    const totalPages = Math.ceil(total / INV_PAGE_SIZE);
    invCurrentPage = Math.min(invCurrentPage, totalPages || 1);
    const start = (invCurrentPage - 1) * INV_PAGE_SIZE;
    const end = Math.min(start + INV_PAGE_SIZE, total);
    matched.slice(start, end).forEach((r) => (r.style.display = ""));
    renderPagination(total, totalPages, start + 1, end);
  } else {
    matched.forEach((r) => (r.style.display = ""));
    hidePagination();
  }

  const noResults = document.getElementById("inv-no-results");
  if (noResults)
    noResults.style.display = matched.length === 0 ? "block" : "none";
}

function renderPagination(total, totalPages, start, end) {
  const pag = document.getElementById("inv-pagination");
  const info = document.getElementById("inv-page-info");
  const pages = document.getElementById("inv-pages");
  if (!pag) return;
  pag.style.display = total > INV_PAGE_SIZE ? "flex" : "none";
  info.textContent = `Showing ${start}–${end} of ${total}`;

  pages.innerHTML = "";

  const prev = document.createElement("button");
  prev.className = "inv-page-btn";
  prev.innerHTML = "&#8249;";
  prev.disabled = invCurrentPage === 1;
  prev.onclick = () => goToPage(invCurrentPage - 1);
  pages.appendChild(prev);

  const range = 2;
  for (let i = 1; i <= totalPages; i++) {
    if (
      i === 1 ||
      i === totalPages ||
      (i >= invCurrentPage - range && i <= invCurrentPage + range)
    ) {
      const btn = document.createElement("button");
      btn.className = "inv-page-btn" + (i === invCurrentPage ? " active" : "");
      btn.textContent = i;
      btn.onclick = () => goToPage(i);
      pages.appendChild(btn);
    } else if (
      (i === invCurrentPage - range - 1 && i > 1) ||
      (i === invCurrentPage + range + 1 && i < totalPages)
    ) {
      const dots = document.createElement("button");
      dots.className = "inv-page-btn";
      dots.textContent = "…";
      dots.disabled = true;
      pages.appendChild(dots);
    }
  }

  const next = document.createElement("button");
  next.className = "inv-page-btn";
  next.innerHTML = "&#8250;";
  next.disabled = invCurrentPage === totalPages;
  next.onclick = () => goToPage(invCurrentPage + 1);
  pages.appendChild(next);
}

function goToPage(page) {
  invCurrentPage = page;
  applyInventoryFilter();
  document
    .getElementById("inventory-table")
    ?.scrollIntoView({ behavior: "smooth", block: "start" });
}

function hidePagination() {
  const pag = document.getElementById("inv-pagination");
  if (pag) pag.style.display = "none";
}

document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.getElementById("inventory-search");
  if (searchInput) searchInput.addEventListener("input", applyInventoryFilter);
  applyInventoryFilter();
  applyExpiryFilter();

  Object.keys(buttons).forEach((key) => {
  if (buttons[key])
    buttons[key].addEventListener("click", () => showSection(key));
  });

  const urlParams = new URLSearchParams(window.location.search);
  const section = urlParams.get('section');
  if (section && contents[section]) {
      showSection(section);
  }
});

function openEditModal(id) {
  if (isGuest) {
    showToast("Guests cannot edit medicines.", "error");
    return;
  }

  fetch("get_medicine.php?id=" + id)
    .then((r) => r.json())
    .then((data) => {
      document.getElementById("edit_id").value = data.id;
      document.getElementById("edit_name").value = data.name;
      document.getElementById("edit_type").value = data.type;
      document.getElementById("edit_batch_date").value = data.batch_date;
      document.getElementById("edit_expired_date").value = data.expired_date;
      document.getElementById("edit_quantity").value = data.quantity;
      document.getElementById("editModal").style.display = "flex";
    });
}

function closeEditModal() {
  document.getElementById("editModal").style.display = "none";
}

function openAddMedicineModal() {
  document.getElementById("addMedicineModal").style.display = "flex";
}

function closeAddMedicineModal() {
  document.getElementById("addMedicineModal").style.display = "none";
}

function openDeleteModal(id, name) {
  if (isGuest) {
    showToast("Guests cannot delete medicines.", "error");
    return;
  }
  document.getElementById("deleteMedicineName").textContent = name;
  document.getElementById("confirmDelete").href =
    "dashboard.php?delete=" + id;
  document.getElementById("deleteModal").style.display = "block";
}

function closeDeleteModal() {
  document.getElementById("deleteModal").style.display = "none";
}

function openCategory(category) {
  document.getElementById("categoryTitle").textContent = category + "";
  document.getElementById("categoryMedicines").innerHTML = "<p>Loading...</p>";
  fetch(
    "get_medicines_by_category.php?category=" + encodeURIComponent(category),
  )
    .then((response) => response.text())
    .then(
      (data) => (document.getElementById("categoryMedicines").innerHTML = data),
    );
  document.getElementById("categoryModal").style.display = "block";
}

function closeCategoryModal() {
  document.getElementById("categoryModal").style.display = "none";
}

// ── Expiry Tracker filter + pagination ──
let expActiveStatus = "all";
const EXP_PAGE_SIZE = 6;
let expCurrentPage = 1;

function expFilter(status, btn) {
  expActiveStatus = status;
  expCurrentPage = 1;
  document
    .querySelectorAll(
      "#exp-pill-all, #exp-pill-expiring, #exp-pill-expired, #exp-pill-low",
    )
    .forEach((p) => p.classList.remove("active"));
  if (btn) btn.classList.add("active");
  applyExpiryFilter();
}

function applyExpiryFilter() {
  const category =
    document.getElementById("expiry-category-filter")?.value || "";
  const allRows = [...document.querySelectorAll("#expiry-full-table tbody tr")];

  const matched = allRows.filter((row) => {
    const rowStatus = row.dataset.status || "";
    const rowCat = row.dataset.category || "";
    const statusMatch =
      expActiveStatus === "all" || rowStatus === expActiveStatus;
    const catMatch = !category || rowCat === category;
    return statusMatch && catMatch;
  });

  allRows.forEach((r) => (r.style.display = "none"));

  const total = matched.length;
  const totalPages = Math.ceil(total / EXP_PAGE_SIZE);
  expCurrentPage = Math.min(expCurrentPage, totalPages || 1);
  const start = (expCurrentPage - 1) * EXP_PAGE_SIZE;
  const end = Math.min(start + EXP_PAGE_SIZE, total);
  matched.slice(start, end).forEach((r) => (r.style.display = ""));

  renderExpiryPagination(total, totalPages, start + 1, end);
}

function renderExpiryPagination(total, totalPages, start, end) {
  const pag = document.getElementById("exp-pagination");
  const info = document.getElementById("exp-page-info");
  const pages = document.getElementById("exp-pages");
  if (!pag) return;
  pag.style.display = total > EXP_PAGE_SIZE ? "flex" : "none";
  info.textContent =
    total === 0 ? "No results" : `Showing ${start}–${end} of ${total}`;

  pages.innerHTML = "";
  const prev = document.createElement("button");
  prev.className = "inv-page-btn";
  prev.innerHTML = "&#8249;";
  prev.disabled = expCurrentPage === 1;
  prev.onclick = () => goToExpPage(expCurrentPage - 1);
  pages.appendChild(prev);

  const range = 2;
  for (let i = 1; i <= totalPages; i++) {
    if (
      i === 1 ||
      i === totalPages ||
      (i >= expCurrentPage - range && i <= expCurrentPage + range)
    ) {
      const btn = document.createElement("button");
      btn.className = "inv-page-btn" + (i === expCurrentPage ? " active" : "");
      btn.textContent = i;
      btn.onclick = () => goToExpPage(i);
      pages.appendChild(btn);
    } else if (
      (i === expCurrentPage - range - 1 && i > 1) ||
      (i === expCurrentPage + range + 1 && i < totalPages)
    ) {
      const dots = document.createElement("button");
      dots.className = "inv-page-btn";
      dots.textContent = "…";
      dots.disabled = true;
      pages.appendChild(dots);
    }
  }

  const next = document.createElement("button");
  next.className = "inv-page-btn";
  next.innerHTML = "&#8250;";
  next.disabled = expCurrentPage === totalPages;
  next.onclick = () => goToExpPage(expCurrentPage + 1);
  pages.appendChild(next);
}

function goToExpPage(page) {
  expCurrentPage = page;
  applyExpiryFilter();
  document
    .getElementById("expiry-full-table")
    ?.scrollIntoView({ behavior: "smooth", block: "start" });
}

function openHistoryCategory(category) {
  document.getElementById("historyCategoryTitle").textContent =
    `Expired: ${category}`;
  document.getElementById("historyMedicines").innerHTML = "<p>Loading...</p>";
  document.getElementById("historyModal").style.display = "block";
  fetch(
    "history.php?action=get_category&category=" + encodeURIComponent(category),
  )
    .then((r) => r.text())
    .then(
      (html) => (document.getElementById("historyMedicines").innerHTML = html),
    );
}

function closeHistoryModal() {
  document.getElementById("historyModal").style.display = "none";
}

function loadHistoryCategories() {
  fetch("history.php?action=get_counts")
    .then((r) => r.json())
    .then((data) => {
      const container = document.getElementById("history-categories");
      container.innerHTML = "";
      Object.keys(data).forEach((cat) => {
        const count = data[cat];
        const card = document.createElement("div");
        card.className = "category-card";
        card.style.background = "#e53935";
        card.onclick = () => openHistoryCategory(cat);
        card.innerHTML = `<h3>${cat}</h3>${count ? `<span class="category-badge">${count}</span>` : ""}`;
        container.appendChild(card);
      });
    });
}

function openModal() {
  document.getElementById("notificationModal").style.display = "block";
}
function closeModal() {
  document.getElementById("notificationModal").style.display = "none";
}

function showToast(message, type = "success") {
  const container = document.getElementById("toast-container");
  const toast = document.createElement("div");
  toast.className = `toast ${type}`;
  toast.innerHTML = message;
  container.appendChild(toast);
  setTimeout(() => toast.classList.add("show"), 100);
  setTimeout(() => {
    toast.classList.remove("show");
    setTimeout(() => toast.remove(), 400);
  }, 7000);
}

// ── Chatbot ──
const chatHead = document.getElementById("chatHead");
const chatContainer = document.getElementById("chatContainer");
const chatClose = document.getElementById("chatClose");
const chatbox = document.getElementById("chatbox");
const chatInput = document.getElementById("chatMessage");

chatHead.addEventListener("click", () => {
  chatContainer.style.display = "flex";
  chatHead.style.display = "none";
});
chatClose.addEventListener("click", () => {
  chatContainer.style.display = "none";
  chatHead.style.display = "flex";
});

function appendMsg(sender, text) {
  const msgDiv = document.createElement("div");
  msgDiv.classList.add("message", sender);
  const avatar = document.createElement("img");
  avatar.className = "avatar";
  avatar.src =
    sender === "user"
      ? "https://ui-avatars.com/api/?name=You&background=6c757d&color=fff"
      : "https://ui-avatars.com/api/?name=MedBot&background=007BFF&color=fff";
  avatar.alt = sender;
  const textDiv = document.createElement("div");
  textDiv.className = "message-text";
  textDiv.textContent = text;
  msgDiv.appendChild(avatar);
  msgDiv.appendChild(textDiv);
  chatbox.appendChild(msgDiv);
  chatbox.scrollTop = chatbox.scrollHeight;
}

function sendChatbotMessage() {
  const message = chatInput.value.trim();
  if (!message) return;
  appendMsg("user", message);
  chatInput.value = "";

  const typingDiv = document.createElement("div");
  typingDiv.classList.add("message", "bot", "typing-indicator");
  typingDiv.id = "typingIndicator";
  typingDiv.innerHTML = "<span></span><span></span><span></span>";
  chatbox.appendChild(typingDiv);
  chatbox.scrollTop = chatbox.scrollHeight;

  fetch("chatbot.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "message=" + encodeURIComponent(message),
  })
    .then((r) => r.json())
    .then((data) => {
      document.getElementById("typingIndicator")?.remove();
      appendMsg("bot", data.reply);
    })
    .catch(() => {
      document.getElementById("typingIndicator")?.remove();
      appendMsg("bot", "⚠️ Something went wrong.");
    });
}

chatInput.addEventListener("keypress", (e) => {
  if (e.key === "Enter") sendChatbotMessage();
});

const SpeechRecognition =
  window.SpeechRecognition || window.webkitSpeechRecognition;
if (SpeechRecognition) {
  const recognition = new SpeechRecognition();
  recognition.lang = "en-US";
  document.getElementById("micBtn").addEventListener("click", () => {
    document.getElementById("micBtn").innerHTML =
      '<i class="fas fa-spinner fa-spin"></i>';
    recognition.start();
  });
  recognition.addEventListener(
    "result",
    (e) => (chatInput.value = e.results[0][0].transcript),
  );
  recognition.addEventListener("end", () => {
    document.getElementById("micBtn").innerHTML =
      '<i class="fas fa-microphone"></i>';
    sendChatbotMessage();
  });
  recognition.onerror = () => {
    document.getElementById("micBtn").innerHTML =
      '<i class="fas fa-microphone"></i>';
    appendMsg("bot", "🎙️ Voice error. Try again.");
  };
}

function printReport(tableId) {
  const printContent = document.getElementById(tableId).outerHTML;
  const originalContent = document.body.innerHTML;
  document.body.innerHTML = `
    <h2>BENE MediCon - Expiration Inventory Report</h2>
    <p><strong>Generated on:</strong> ${new Date().toLocaleString()}</p>
    ${printContent}
    `;
  window.print();
  document.body.innerHTML = originalContent;
  location.reload();
}

function toggleProfileMenu() {
  const dropdown = document.getElementById("profileDropdown");
  dropdown.classList.toggle("show");
  document.addEventListener("click", function closeDropdown(e) {
    const profile = document.querySelector(".profile-menu");
    if (profile && !profile.contains(e.target)) {
      dropdown.classList.remove("show");
      document.removeEventListener("click", closeDropdown);
    }
  });
}

function openLogoutModal() {
  document.getElementById("logoutModal").style.display = "block";
  document.getElementById("profileDropdown").classList.remove("show");
}
function closeLogoutModal() {
  document.getElementById("logoutModal").style.display = "none";
}

function switchDonateView(view, btn) {
  document
    .querySelectorAll("#donate-pill, #dispose-pill")
    .forEach((p) => p.classList.remove("active"));
  if (btn) btn.classList.add("active");
  document.getElementById("donate-view").style.display =
    view === "donate" ? "block" : "none";
  document.getElementById("dispose-view").style.display =
    view === "dispose" ? "block" : "none";
}

function switchRequestView(view, btn) {
  document
    .querySelectorAll("#req-pill-donations, #req-pill-disposals")
    .forEach((p) => p.classList.remove("active"));
  if (btn) btn.classList.add("active");
  document.getElementById("req-view-donations").style.display =
    view === "donations" ? "block" : "none";
  document.getElementById("req-view-disposals").style.display =
    view === "disposals" ? "block" : "none";
}

function openDisposalModal(medId, medName) {
  if (isGuest) {
    showToast("Guests cannot dispose medicines.", "error");
    return;
  }
  document.getElementById("disposalMedId").value = medId;
  document.getElementById("disposalMedName").textContent = medName;
  document.getElementById("disposalModal").style.display = "block";
}

function closeDisposalModal() {
  document.getElementById("disposalModal").style.display = "none";
}
