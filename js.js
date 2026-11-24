(() => {
  "use strict";

  const $ = (selector, root = document) => root.querySelector(selector);
  const escapeHtml = value => String(value ?? "").replace(/[&<>"']/g, char => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  })[char]);
  const state = {
    user: null, csrf: "", current: "dash", summary: {}, classSummary: [], programSummary: [], students: [], prodi: [], dosen: [],
    editing: null, studentQuery: "", prodiFilter: "", cohortFilter: "", sort: "nim", direction: 1,
    classes: [], myKrs: [], currentTerm: "", selectedClass: "", selectedNim: "", selectedTerm: "", khsStudents: [], noticeTimer: null
  };
  const labels = {
    admin: { dash: "Dashboard", mhs: "Mahasiswa", nilai: "Input Nilai", khs: "KHS & IPK", password: "Ganti password" },
    dosen: { dash: "Dashboard", krs: "Persetujuan KRS", nilai: "Input Nilai", password: "Ganti password" },
    mhs: { krs: "KRS Saya", khs: "KHS & IPK", password: "Ganti password" }
  };
  const roleNames = { admin: "Admin", dosen: "Dosen", mhs: "Mahasiswa" };

  function notice(message, ok = false) {
    const box = $("#msg");
    box.className = `notice ${ok ? "ok" : "error"}`;
    box.textContent = message;
    clearTimeout(state.noticeTimer);
    state.noticeTimer = setTimeout(() => { box.textContent = ""; box.className = ""; }, 5000);
  }

  async function request(endpoint, options = {}) {
    const headers = { Accept: "application/json", ...(options.headers || {}) };
    if (options.body && typeof options.body !== "string") {
      options.body = JSON.stringify(options.body);
      headers["Content-Type"] = "application/json";
    }
    if (state.csrf && options.method && options.method !== "GET") headers["X-CSRF-Token"] = state.csrf;
    let response;
    try {
      response = await fetch(`api/${endpoint}`, {
        credentials: "same-origin", ...options, headers
      });
    } catch (error) {
      throw new Error("Tidak dapat menghubungi server. Pastikan Apache dan MySQL berjalan.");
    }
    let payload;
    try {
      payload = await response.json();
    } catch (error) {
      throw new Error("Server mengembalikan respons JSON yang tidak valid.");
    }
    if (!response.ok) {
      if (response.status === 401 && endpoint !== "login.php") {
        state.user = null;
        state.csrf = "";
        renderLogin();
      }
      const error = new Error(payload.error || `Permintaan gagal (${response.status}).`);
      error.status = response.status;
      throw error;
    }
    return payload;
  }

  function busy(message = "Memuat data...") {
    $("#v").innerHTML = `<div class="card loading">${escapeHtml(message)}</div>`;
  }

  function renderLogin() {
    state.user = null;
    $("#nav").innerHTML = "";
    $("#v").innerHTML = `
      <section class="card login-card">
        <aside class="login-art" aria-label="Informasi SIAKAD">
          <div class="login-art-top"><span class="login-mark" aria-hidden="true">S</span><span>SIAKAD <b>MINI</b></span></div>
          <div class="login-art-copy">
            <p class="login-eyebrow">PORTAL AKADEMIK</p>
            <h2>Langkah kecil hari ini, masa depan besar nanti.</h2>
            <p>Kelola perjalanan akademikmu dalam satu ruang yang sederhana dan terarah.</p>
          </div>
          <div class="login-features"><span>✦ Akademik</span><span>✦ Terintegrasi</span><span>✦ Aman</span></div>
        </aside>
        <div class="login-panel">
          <p class="login-kicker">Selamat datang kembali</p>
          <h2>Masuk ke akunmu</h2>
          <p class="login-description">Gunakan akun admin, dosen, atau mahasiswa untuk melanjutkan.</p>
          <form id="login-form">
            <div class="form-grid">
              <label>Username
                <input name="username" autocomplete="username" placeholder="Masukkan username" required maxlength="50">
              </label>
              <label>Password
                <span class="password-field">
                  <input id="login-password" name="password" type="password" autocomplete="current-password" placeholder="Masukkan password" required>
                  <button class="password-toggle" type="button" data-password-toggle aria-controls="login-password" aria-label="Tampilkan password">Tampilkan</button>
                </span>
              </label>
            </div>
            <button class="b login-submit" type="submit">Masuk</button>
          </form>
          <p class="login-footnote">Akun demo dibuat melalui <code>database/seed_users.php</code>. Password awal sama dengan username; segera ganti setelah masuk.</p>
        </div>
      </section>`;
  }

  function renderNav() {
    const tabs = labels[state.user.role];
    $("#nav").innerHTML = Object.entries(tabs).map(([key, title]) =>
      `<button type="button" class="${key === state.current ? "on" : ""}" data-view="${key}">${escapeHtml(title)}</button>`
    ).join("") + `<span class="muted" style="align-self:center;margin-left:auto">${escapeHtml(state.user.nama)} · ${escapeHtml(roleNames[state.user.role])}</span>
      <button type="button" class="s" data-action="logout">Keluar</button>`;
  }

  function stat(title, value) {
    return `<div class="stat"><b>${escapeHtml(value)}</b>${escapeHtml(title)}</div>`;
  }

  function dashboardView() {
    let cards = "";
    let details = "";
    if (state.user.role === "admin") {
      cards = [
        ["Mahasiswa", state.summary.mahasiswa], ["Dosen", state.summary.dosen],
        ["Kelas dibuka", state.summary.kelas], ["KRS disetujui", state.summary.disetujui],
        ["KRS menunggu", state.summary.menunggu]
      ].map(([label, count]) => stat(label, count ?? 0)).join("");
      const maxStudents = Math.max(1, ...state.programSummary.map(item => Number(item.jumlah)));
      details = `<section class="card"><h2>Kuota kelas</h2><div class="table-wrap"><table>
        <thead><tr><th>Kelas</th><th>Mata kuliah</th><th>Dosen</th><th>Terisi</th><th></th></tr></thead><tbody>
        ${state.classSummary.map(item => {
          const filled = Number(item.terisi || 0);
          const percent = Math.min(100, Number(item.kuota) ? filled / Number(item.kuota) * 100 : 0);
          return `<tr><td>${escapeHtml(item.id_kelas)}</td><td>${escapeHtml(item.nama_mk)}</td><td>${escapeHtml(item.nama_dosen)}</td>
            <td>${filled}/${escapeHtml(item.kuota)}</td><td><div class="bar"><i style="width:${percent}%"></i></div></td></tr>`;
        }).join("")}</tbody></table></div></section>
        <section class="card"><h2>Mahasiswa per program studi</h2><div class="table-wrap"><table><tbody>
        ${state.programSummary.map(item => {
          const count = Number(item.jumlah);
          return `<tr><td>${escapeHtml(item.nama_prodi)}</td><td>${count}</td><td><div class="bar"><i style="width:${count / maxStudents * 100}%"></i></div></td></tr>`;
        }).join("")}</tbody></table></div></section>`;
    } else if (state.user.role === "dosen") {
      cards = stat("Pengajuan KRS bimbingan menunggu", state.summary.menunggu ?? 0);
    } else {
      cards = stat("Mata kuliah dalam KRS", state.summary.jumlah ?? 0) +
        stat("Beban SKS aktif", `${state.summary.sks ?? 0} / 24`);
    }
    return `<div class="grid card">${cards}</div>
      <section class="card">
        <h2>Selamat datang, ${escapeHtml(state.user.nama)}</h2>
        <p class="muted">Gunakan menu di atas untuk mengelola kegiatan akademik sesuai hak akses akun Anda.</p>
        ${state.user.role === "admin" ? `<button type="button" class="b" data-view="mhs">Kelola mahasiswa</button>` : ""}
        ${state.user.role === "dosen" ? `<button type="button" class="b" data-view="krs">Periksa pengajuan KRS</button>` : ""}
        ${state.user.role === "mhs" ? `<button type="button" class="b" data-view="krs">Buka KRS</button>` : ""}
      </section>
      ${details}
      <section class="card"><h2>Informasi akademik</h2>
        <p class="muted">Nilai akhir menggunakan komposisi dari aplikasi awal: 30% tugas, 30% UTS, dan 40% UAS. Batas KRS adalah 24 SKS.</p>
      </section>`;
  }

  function studentForm() {
    const student = state.editing || {};
    const prodiOptions = state.prodi.map(item =>
      `<option value="${escapeHtml(item.id_prodi)}" ${student.id_prodi === item.id_prodi ? "selected" : ""}>${escapeHtml(item.nama_prodi)}</option>`
    ).join("");
    const dosenOptions = state.dosen.map(item =>
      `<option value="${escapeHtml(item.nidn)}" ${student.nidn_wali === item.nidn ? "selected" : ""}>${escapeHtml(item.nama_dosen)}</option>`
    ).join("");
    return `<section class="card">
      <h2>${state.editing ? "Ubah mahasiswa" : "Tambah mahasiswa"}</h2>
      <form id="student-form">
        <div class="form-grid">
          <label>NIM<input name="nim" required maxlength="10" value="${escapeHtml(student.nim)}" ${state.editing ? "readonly" : ""}></label>
          <label>Nama<input name="nama_mhs" required maxlength="80" value="${escapeHtml(student.nama_mhs)}"></label>
          <label>Jenis kelamin<select name="jenis_kelamin" required>
            <option value="L" ${student.jenis_kelamin === "L" ? "selected" : ""}>Laki-laki</option>
            <option value="P" ${student.jenis_kelamin === "P" ? "selected" : ""}>Perempuan</option>
          </select></label>
          <label>Tanggal lahir<input name="tgl_lahir" type="date" required value="${escapeHtml(student.tgl_lahir)}"></label>
          <label>Angkatan<input name="angkatan" type="number" min="1901" max="2155" required value="${escapeHtml(student.angkatan || new Date().getFullYear())}"></label>
          <label>Program studi<select name="id_prodi" required>${prodiOptions}</select></label>
          <label>Dosen wali<select name="nidn_wali" required>${dosenOptions}</select></label>
          <label>Alamat<input name="alamat" maxlength="100" value="${escapeHtml(student.alamat)}"></label>
        </div>
        <button class="b" type="submit">Simpan</button>
        ${state.editing ? `<button class="s" type="button" data-action="student-cancel">Batal</button>` : ""}
      </form>
    </section>`;
  }

  function studentsView() {
    const cohorts = [...new Set(state.students.map(item => String(item.angkatan)))].sort().reverse();
    const query = state.studentQuery.toLocaleLowerCase("id");
    const filtered = state.students.filter(item =>
      (!query || `${item.nim} ${item.nama_mhs} ${item.nama_prodi}`.toLocaleLowerCase("id").includes(query)) &&
      (!state.prodiFilter || item.id_prodi === state.prodiFilter) &&
      (!state.cohortFilter || String(item.angkatan) === state.cohortFilter)
    ).sort((a, b) => {
      const left = String(a[state.sort] ?? "").toLocaleLowerCase("id");
      const right = String(b[state.sort] ?? "").toLocaleLowerCase("id");
      return left.localeCompare(right, "id", { numeric: true }) * state.direction;
    });
    const headers = [["nim", "NIM"], ["nama_mhs", "Nama"], ["jenis_kelamin", "L/P"], ["angkatan", "Angkatan"], ["nama_prodi", "Prodi"], ["nama_dosen", "Dosen wali"]];
    return `${studentForm()}
      <section class="card">
        <h2>Data mahasiswa</h2>
        <div class="toolbar">
          <input id="student-search" placeholder="Cari NIM, nama, atau prodi..." value="${escapeHtml(state.studentQuery)}" aria-label="Cari mahasiswa">
          <select id="prodi-filter" aria-label="Filter program studi"><option value="">Semua prodi</option>${state.prodi.map(p => `<option value="${escapeHtml(p.id_prodi)}" ${state.prodiFilter === p.id_prodi ? "selected" : ""}>${escapeHtml(p.nama_prodi)}</option>`).join("")}</select>
          <select id="cohort-filter" aria-label="Filter angkatan"><option value="">Semua angkatan</option>${cohorts.map(year => `<option ${state.cohortFilter === year ? "selected" : ""}>${escapeHtml(year)}</option>`).join("")}</select>
        </div>
        <div class="table-wrap"><table><thead><tr>${headers.map(([key, label]) =>
          `<th><button type="button" class="s" data-sort="${key}">${label}${state.sort === key ? (state.direction > 0 ? " ▲" : " ▼") : ""}</button></th>`
        ).join("")}<th>Aksi</th></tr></thead><tbody>
        ${filtered.map(item => `<tr>
          <td>${escapeHtml(item.nim)}</td><td>${escapeHtml(item.nama_mhs)}</td><td>${escapeHtml(item.jenis_kelamin)}</td>
          <td>${escapeHtml(item.angkatan)}</td><td>${escapeHtml(item.nama_prodi)}</td><td>${escapeHtml(item.nama_dosen)}</td>
          <td class="actions"><button type="button" class="s" data-action="student-edit" data-id="${escapeHtml(item.nim)}">Ubah</button>
          <button type="button" class="s d" data-action="student-delete" data-id="${escapeHtml(item.nim)}">Hapus</button></td>
        </tr>`).join("") || `<tr><td class="empty" colspan="7">Tidak ada mahasiswa yang cocok.</td></tr>`}
        </tbody></table></div>
      </section>`;
  }

  function formatTime(value) {
    return String(value || "").slice(0, 5);
  }

  function statusTag(status) {
    const klass = status === "Disetujui" ? "approved" : status === "Ditolak" ? "rejected" : "pending";
    return `<span class="status ${klass}">${escapeHtml(status)}</span>`;
  }

  function scheduleView(records) {
    const days = ["Senin", "Selasa", "Rabu", "Kamis", "Jumat"];
    return `<div class="schedule">${days.map(day => `<div class="schedule-day"><b>${day}</b>${
      records.filter(item => item.hari === day && item.status !== "Ditolak")
        .sort((a, b) => formatTime(a.jam_mulai).localeCompare(formatTime(b.jam_mulai)))
        .map(item => `<span class="schedule-item">${escapeHtml(formatTime(item.jam_mulai))} ${escapeHtml(item.nama_mk)} (${escapeHtml(item.ruang)})</span>`).join("")
    }</div>`).join("")}</div>`;
  }

  function studentKrsView() {
    const active = state.myKrs.filter(item => item.status !== "Ditolak");
    const totalSks = active.reduce((sum, item) => sum + Number(item.sks), 0);
    const selected = new Set(active.map(item => item.id_kelas));
    const available = state.classes.filter(item => !selected.has(item.id_kelas));
    return `<section class="card">
      <h2>${escapeHtml(state.user.nama)} <span class="pill">${escapeHtml(state.currentTerm)} · ${totalSks} / 24 SKS</span></h2>
      <div class="bar"><i style="width:${Math.min(100, totalSks / 24 * 100)}%"></i></div>
    </section>
    <section class="card"><h2>KRS saya</h2><div class="table-wrap"><table>
      <thead><tr><th>Kelas</th><th>Mata kuliah</th><th>SKS</th><th>Jadwal</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>${state.myKrs.map(item => `<tr>
        <td>${escapeHtml(item.id_kelas)}</td><td>${escapeHtml(item.nama_mk)}</td><td>${escapeHtml(item.sks)}</td>
        <td>${escapeHtml(item.hari)} ${escapeHtml(formatTime(item.jam_mulai))} · ${escapeHtml(item.tahun_ajaran)} ${escapeHtml(item.semester)}</td>
        <td>${statusTag(item.status)}</td><td>${item.status !== "Ditolak" ? `<button type="button" class="s d" data-action="krs-cancel" data-id="${escapeHtml(item.id_krs)}">Batal</button>` : "—"}</td>
      </tr>`).join("") || `<tr><td class="empty" colspan="6">Belum ada pengajuan KRS.</td></tr>`}</tbody>
    </table></div></section>
    <section class="card"><h2>Jadwal mingguan</h2>${scheduleView(active)}</section>
    <section class="card"><h2>Kelas tersedia</h2><div class="table-wrap"><table>
      <thead><tr><th>Kelas</th><th>Mata kuliah</th><th>SKS</th><th>Dosen</th><th>Jadwal</th><th>Kuota</th><th></th></tr></thead>
      <tbody>${available.map(item => {
        const remaining = Number(item.kuota) - Number(item.terisi || 0);
        const blocked = remaining <= 0;
        return `<tr><td>${escapeHtml(item.id_kelas)}</td><td>${escapeHtml(item.nama_mk)}</td><td>${escapeHtml(item.sks)}</td>
          <td>${escapeHtml(item.nama_dosen)}</td><td>${escapeHtml(item.hari)} ${escapeHtml(formatTime(item.jam_mulai))} · ${escapeHtml(item.tahun_ajaran)} ${escapeHtml(item.semester)}</td>
          <td>${Math.max(remaining, 0)} / ${escapeHtml(item.kuota)}</td><td><button type="button" class="b" data-action="krs-submit" data-id="${escapeHtml(item.id_kelas)}" ${blocked ? "disabled" : ""}>Ambil</button></td></tr>`;
      }).join("") || `<tr><td class="empty" colspan="7">Semua kelas sudah ada dalam KRS aktif.</td></tr>`}</tbody>
    </table></div><p class="muted hint">Batas SKS, bentrok jadwal, duplikasi, dan kuota divalidasi kembali oleh server sebelum pengajuan disimpan.</p></section>`;
  }

  function approvalsView(records) {
    return `<section class="card"><h2>Persetujuan KRS mahasiswa bimbingan</h2><div class="table-wrap"><table>
      <thead><tr><th>Tanggal</th><th>Mahasiswa</th><th>Kelas</th><th>Mata kuliah</th><th>SKS</th><th>Jadwal</th><th>Aksi</th></tr></thead>
      <tbody>${records.map(item => `<tr>
        <td>${escapeHtml(item.tgl_krs)}</td><td>${escapeHtml(item.nim)} · ${escapeHtml(item.nama_mhs)}</td>
        <td>${escapeHtml(item.id_kelas)}</td><td>${escapeHtml(item.nama_mk)}</td><td>${escapeHtml(item.sks)}</td>
        <td>${escapeHtml(item.hari)} ${escapeHtml(formatTime(item.jam_mulai))} · ${escapeHtml(item.ruang)}</td>
        <td class="actions"><button type="button" class="b" data-action="approval" data-id="${escapeHtml(item.id_krs)}" data-decision="Disetujui">Setujui</button>
        <button type="button" class="s d" data-action="approval" data-id="${escapeHtml(item.id_krs)}" data-decision="Ditolak">Tolak</button></td>
      </tr>`).join("") || `<tr><td class="empty" colspan="7">Tidak ada pengajuan KRS yang menunggu.</td></tr>`}</tbody>
    </table></div></section>`;
  }

  function gradesView(data) {
    const options = data.classes.map(item => `<option value="${escapeHtml(item.id_kelas)}" ${data.selected === item.id_kelas ? "selected" : ""}>${escapeHtml(item.id_kelas)} · ${escapeHtml(item.nama_mk)}</option>`).join("");
    return `<section class="card">
      <h2>Input Nilai</h2><label class="field">Kelas<select id="grade-class">${options}</select></label>
      <p class="muted">Nilai akhir = 30% tugas + 30% UTS + 40% UAS. Hanya KRS yang disetujui yang dapat dinilai. Huruf: A ≥ 80, B ≥ 70, C ≥ 60, D ≥ 50, selain itu E.</p>
    </section>
    <section class="card"><div class="table-wrap"><table>
      <thead><tr><th>NIM</th><th>Nama</th><th>Tugas</th><th>UTS</th><th>UAS</th><th>Nilai akhir</th><th>Huruf</th><th></th></tr></thead>
      <tbody>${data.students.map(item => `<tr data-grade-row="${escapeHtml(item.id_krs)}">
        <td>${escapeHtml(item.nim)}</td><td>${escapeHtml(item.nama_mhs)}</td>
        ${["tugas", "uts", "uas"].map(field => `<td><input class="score" data-score="${field}" type="number" min="0" max="100" step="0.01" value="${escapeHtml(item[`nilai_${field}`] ?? "")}" aria-label="${field}"></td>`).join("")}
        <td data-final>${escapeHtml(item.nilai_akhir ?? "—")}</td><td data-letter>${escapeHtml(item.nilai_huruf ?? "—")}</td>
        <td><button type="button" class="b" data-action="grade-save" data-id="${escapeHtml(item.id_krs)}">Simpan</button></td>
      </tr>`).join("") || `<tr><td class="empty" colspan="8">Belum ada peserta dengan KRS disetujui di kelas ini.</td></tr>`}</tbody>
    </table></div></section>`;
  }

  function passwordView() {
    return `<section class="card login-card"><h2>Ganti password</h2>
      <form id="password-form"><div class="form-grid">
        <label>Password saat ini<input name="current_password" type="password" autocomplete="current-password" required></label>
        <label>Password baru (minimal 12 karakter)<input name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="1024" required></label>
        <label>Ulangi password baru<input name="confirm_password" type="password" autocomplete="new-password" minlength="12" maxlength="1024" required></label>
      </div><button class="b" type="submit">Simpan password</button></form></section>`;
  }

  async function khsView() {
    const params = new URLSearchParams();
    if (state.user.role !== "mhs") params.set("nim", state.selectedNim);
    if (state.selectedTerm) params.set("term", state.selectedTerm);
    const query = params.toString() ? `?${params}` : "";
    const data = await request(`khs.php${query}`);
    state.selectedTerm = data.selected_term || "";
    const selector = state.user.role === "mhs" ? "" : `<label class="field">Mahasiswa<select id="khs-student">
      ${state.khsStudents.map(item => `<option value="${escapeHtml(item.nim)}" ${item.nim === data.student.nim ? "selected" : ""}>${escapeHtml(item.nim)} · ${escapeHtml(item.nama_mhs)}</option>`).join("")}
    </select></label>`;
    const termSelector = `<label class="field">Semester<select id="khs-term">${data.terms.map(term => `<option ${term === data.selected_term ? "selected" : ""}>${escapeHtml(term)}</option>`).join("") || `<option value="">Belum ada nilai</option>`}</select></label>`;
    return `<section class="card toolbar no-print">${selector}${termSelector}<button type="button" class="s" data-action="print">Cetak</button></section>
      <section class="card"><h2>Kartu Hasil Studi</h2>
      <p>${escapeHtml(data.student.nim)} · ${escapeHtml(data.student.nama_mhs)} · ${escapeHtml(data.student.nama_prodi)} · Angkatan ${escapeHtml(data.student.angkatan)}</p>
      <div class="table-wrap"><table><thead><tr><th>Kode</th><th>Mata kuliah</th><th>SKS</th><th>Nilai akhir</th><th>Huruf</th><th>Bobot</th><th>Mutu</th></tr></thead>
      <tbody>${data.courses.map(item => `<tr><td>${escapeHtml(item.kode_mk)}</td><td>${escapeHtml(item.nama_mk)}</td><td>${escapeHtml(item.sks)}</td>
        <td>${Number(item.nilai_akhir).toFixed(2)}</td><td>${escapeHtml(item.nilai_huruf)}</td><td>${Number(item.bobot).toFixed(2)}</td><td>${Number(item.mutu).toFixed(2)}</td></tr>`).join("") || `<tr><td class="empty" colspan="7">Belum ada nilai yang dapat ditampilkan.</td></tr>`}</tbody>
      </table></div>
      <p>SKS semester ini: <b>${escapeHtml(data.total_sks)}</b> · SKS kumulatif: <b>${escapeHtml(data.cumulative_sks)}</b></p>
      <p>IPS semester ini: <b>${Number(data.ips).toFixed(2)}</b></p>
      <div class="ipk">IPK <span>${Number(data.ipk).toFixed(2)}</span></div>
      <p class="muted hint">IPS/IPK dihitung dari jumlah (SKS × bobot) dibagi total SKS yang sudah memiliki nilai.</p>
      </section>`;
  }

  async function renderView() {
    if (!state.user) return renderLogin();
    renderNav();
    busy();
    try {
      let html;
      if (state.current === "dash") html = dashboardView();
      if (state.current === "mhs") {
        const query = new URLSearchParams({ q: state.studentQuery });
        const data = await request(`mahasiswa.php?${query}`);
        state.students = data.mahasiswa; state.prodi = data.prodi; state.dosen = data.dosen;
        html = studentsView();
      }
      if (state.current === "krs") {
        if (state.user.role === "mhs") {
          const data = await request("krs.php");
          state.classes = data.classes; state.myKrs = data.mine;
          state.currentTerm = data.current_term || "Belum ada periode aktif";
          html = studentKrsView();
        } else {
          const data = await request("persetujuan.php");
          html = approvalsView(data.pending);
        }
      }
      if (state.current === "nilai") {
        const suffix = state.selectedClass ? `?id_kelas=${encodeURIComponent(state.selectedClass)}` : "";
        const data = await request(`nilai.php${suffix}`);
        state.selectedClass = data.selected;
        html = gradesView(data);
      }
      if (state.current === "khs") html = await khsView();
      if (state.current === "password") html = passwordView();
      $("#v").innerHTML = html || "";
    } catch (error) {
      $("#v").innerHTML = `<section class="card"><p class="danger">${escapeHtml(error.message)}</p><button class="s" data-action="refresh">Coba lagi</button></section>`;
    }
  }

  async function loadSession() {
    try {
      const data = await request("dashboard.php");
      state.user = data.user;
      state.csrf = data.csrf;
      state.summary = data.summary;
      state.classSummary = data.class_summary || [];
      state.programSummary = data.program_summary || [];
      if (!labels[state.user.role]) throw new Error("Role akun tidak dikenal.");
      if (state.user.role === "mhs") state.current = "krs";
      else state.current = "dash";
      await renderView();
    } catch (error) {
      if (error.status !== 401) notice(error.message);
      if (!state.user) renderLogin();
    }
  }

  async function submitLogin(form) {
    const values = new FormData(form);
    const submitButton = $("button[type=submit]", form);
    const originalLabel = submitButton.textContent;
    submitButton.disabled = true;
    submitButton.textContent = "Memeriksa...";
    try {
      const result = await request("login.php", {
        method: "POST",
        body: { username: values.get("username"), password: values.get("password") }
      });
      state.user = result.user;
      state.csrf = result.csrf;
      notice(`Selamat datang, ${result.user.username}.`, true);
      await loadSession();
    } catch (error) {
      notice(error.message);
    } finally {
      submitButton.disabled = false;
      submitButton.textContent = originalLabel;
    }
  }

  async function saveStudent(form) {
    const body = Object.fromEntries(new FormData(form));
    body.action = state.editing ? "update" : "create";
    try {
      const result = await request("mahasiswa.php", { method: "POST", body });
      state.editing = null;
      notice(result.message, true);
      await renderView();
    } catch (error) { notice(error.message); }
  }

  async function saveGrade(button) {
    const row = button.closest("[data-grade-row]");
    const body = { id_krs: button.dataset.id };
    row.querySelectorAll("[data-score]").forEach(input => { body[input.dataset.score] = input.value; });
    try {
      const result = await request("nilai.php", { method: "POST", body });
      notice(`${result.message} ${Number(result.nilai_akhir).toFixed(2)} (${result.nilai_huruf}).`, true);
      await renderView();
    } catch (error) { notice(error.message); }
  }

  async function savePassword(form) {
    const values = new FormData(form);
    if (values.get("new_password") !== values.get("confirm_password")) {
      notice("Konfirmasi password baru tidak sama.");
      return;
    }
    try {
      const result = await request("password.php", {
        method: "POST",
        body: { current_password: values.get("current_password"), new_password: values.get("new_password") }
      });
      state.csrf = result.csrf;
      form.reset();
      notice(result.message, true);
    } catch (error) { notice(error.message); }
  }

  async function performAction(button) {
    const action = button.dataset.action;
    if (action === "logout") {
      try {
        await request("logout.php", { method: "POST", body: {} });
        state.user = null; state.csrf = ""; renderLogin();
      } catch (error) { notice(error.message); }
    } else if (action === "student-cancel") {
      state.editing = null; renderView();
    } else if (action === "student-edit") {
      state.editing = state.students.find(item => item.nim === button.dataset.id) || null;
      renderView();
    } else if (action === "student-delete") {
      if (!confirm(`Hapus mahasiswa ${button.dataset.id}?`)) return;
      try {
        const result = await request("mahasiswa.php", { method: "POST", body: { action: "delete", nim: button.dataset.id } });
        notice(result.message, true); await renderView();
      } catch (error) { notice(error.message); }
    } else if (action === "krs-submit") {
      try {
        const result = await request("krs.php", { method: "POST", body: { action: "submit", id_kelas: button.dataset.id } });
        notice(result.message, true); await renderView();
      } catch (error) { notice(error.message); }
    } else if (action === "krs-cancel") {
      if (!confirm("Batalkan pengajuan KRS ini?")) return;
      try {
        const result = await request("krs.php", { method: "POST", body: { action: "cancel", id_krs: button.dataset.id } });
        notice(result.message, true); await renderView();
      } catch (error) { notice(error.message); }
    } else if (action === "approval") {
      try {
        const result = await request("persetujuan.php", {
          method: "POST", body: { id_krs: button.dataset.id, decision: button.dataset.decision }
        });
        notice(result.message, true); await renderView();
      } catch (error) { notice(error.message); }
    } else if (action === "grade-save") {
      await saveGrade(button);
    } else if (action === "print") {
      window.print();
    } else if (action === "refresh") {
      await renderView();
    }
  }

  document.addEventListener("submit", event => {
    if (event.target.id === "login-form") {
      event.preventDefault(); submitLogin(event.target);
    } else if (event.target.id === "student-form") {
      event.preventDefault(); saveStudent(event.target);
    } else if (event.target.id === "password-form") {
      event.preventDefault(); savePassword(event.target);
    }
  });

  document.addEventListener("click", event => {
    const passwordToggle = event.target.closest("[data-password-toggle]");
    if (passwordToggle) {
      const password = $("#login-password");
      const showPassword = password.type === "password";
      password.type = showPassword ? "text" : "password";
      passwordToggle.textContent = showPassword ? "Sembunyikan" : "Tampilkan";
      passwordToggle.setAttribute("aria-label", showPassword ? "Sembunyikan password" : "Tampilkan password");
      return;
    }
    const viewButton = event.target.closest("[data-view]");
    if (viewButton && state.user) {
      state.current = viewButton.dataset.view;
      if (state.current === "khs" && state.user.role !== "mhs" && !state.selectedNim) {
        loadKhsStudents();
        return;
      }
      renderView();
      return;
    }
    const actionButton = event.target.closest("[data-action]");
    if (actionButton) { performAction(actionButton); return; }
    const sortButton = event.target.closest("[data-sort]");
    if (sortButton) {
      const key = sortButton.dataset.sort;
      state.direction = state.sort === key ? -state.direction : 1;
      state.sort = key; renderView();
    }
  });

  async function loadKhsStudents() {
    try {
      const data = await request("khs.php");
      state.khsStudents = data.students || [];
      state.selectedNim = state.khsStudents[0]?.nim || "";
      if (!state.selectedNim) {
        $("#v").innerHTML = `<section class="card"><p class="empty">Belum ada data mahasiswa untuk ditampilkan.</p></section>`;
        return;
      }
      await renderView();
    } catch (error) {
      $("#v").innerHTML = `<section class="card"><p class="danger">${escapeHtml(error.message)}</p></section>`;
    }
  }

  document.addEventListener("input", event => {
    if (event.target.id === "student-search") {
      state.studentQuery = event.target.value;
      const start = event.target.selectionStart;
      const end = event.target.selectionEnd;
      $("#v").innerHTML = studentsView();
      const replacement = $("#student-search");
      replacement.focus();
      replacement.setSelectionRange(start, end);
    }
    const row = event.target.closest("[data-grade-row]");
    if (row && row.querySelectorAll("[data-score]:invalid").length === 0) {
      const inputs = [...row.querySelectorAll("[data-score]")].map(item => Number(item.value));
      if (inputs.every(Number.isFinite) && row.querySelectorAll("[data-score]").length === 3) {
        const final = Math.round((inputs[0] * .3 + inputs[1] * .3 + inputs[2] * .4) * 100) / 100;
        const letter = final >= 80 ? "A" : final >= 70 ? "B" : final >= 60 ? "C" : final >= 50 ? "D" : "E";
        row.querySelector("[data-final]").textContent = final.toFixed(2);
        row.querySelector("[data-letter]").textContent = `${letter} (pratinjau)`;
      }
    }
  });

  document.addEventListener("change", event => {
    if (event.target.id === "prodi-filter") {
      state.prodiFilter = event.target.value; renderView();
    } else if (event.target.id === "cohort-filter") {
      state.cohortFilter = event.target.value; renderView();
    } else if (event.target.id === "grade-class") {
      state.selectedClass = event.target.value; renderView();
    } else if (event.target.id === "khs-student") {
      state.selectedNim = event.target.value; state.selectedTerm = ""; renderView();
    } else if (event.target.id === "khs-term") {
      state.selectedTerm = event.target.value; renderView();
    }
  });

  const savedTheme = localStorage.getItem("siakad-theme");
  if (savedTheme === "light" || savedTheme === "dark") {
    document.documentElement.dataset.theme = savedTheme;
  }
  const syncThemeLabel = () => {
    const current = document.documentElement.dataset.theme ||
      (matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
    $("#theme-toggle").setAttribute("aria-label", current === "dark" ? "Gunakan tema terang" : "Gunakan tema gelap");
  };
  syncThemeLabel();

  $("#theme-toggle").addEventListener("click", () => {
    const root = document.documentElement;
    const current = root.dataset.theme || (matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
    root.dataset.theme = current === "dark" ? "light" : "dark";
    localStorage.setItem("siakad-theme", root.dataset.theme);
    syncThemeLabel();
  });

  loadSession();
})();
