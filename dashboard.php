<?php
require_once __DIR__ . '/../config/api.php';

api_run(function () use ($conn): void {
    api_method('GET');
    $user = api_user();
    $name = $user['username'];
    if ($user['role'] === 'mhs') {
        $stmt = $conn->prepare('SELECT nama_mhs AS nama FROM mahasiswa WHERE nim = ?');
        $stmt->bind_param('s', $user['user_id']);
        $stmt->execute();
        $name = $stmt->get_result()->fetch_assoc()['nama'] ?? $name;
    } elseif ($user['role'] === 'dosen') {
        $stmt = $conn->prepare('SELECT nama_dosen AS nama FROM dosen WHERE nidn = ?');
        $stmt->bind_param('s', $user['user_id']);
        $stmt->execute();
        $name = $stmt->get_result()->fetch_assoc()['nama'] ?? $name;
    }

    $summary = [];
    $classSummary = [];
    $programSummary = [];
    if ($user['role'] === 'admin') {
        foreach ([
            'mahasiswa' => 'SELECT COUNT(*) AS n FROM mahasiswa',
            'dosen' => 'SELECT COUNT(*) AS n FROM dosen',
            'kelas' => 'SELECT COUNT(*) AS n FROM kelas',
            'disetujui' => "SELECT COUNT(*) AS n FROM krs WHERE status = 'Disetujui'",
            'menunggu' => "SELECT COUNT(*) AS n FROM krs WHERE status = 'Menunggu'",
        ] as $key => $query) {
            $summary[$key] = (int) $conn->query($query)->fetch_assoc()['n'];
        }
        $classSummary = $conn->query(
            "SELECT c.id_kelas,mk.nama_mk,d.nama_dosen,c.kuota,
                    COALESCE(SUM(k.status='Disetujui'),0) AS terisi
             FROM kelas c JOIN mata_kuliah mk ON mk.kode_mk=c.kode_mk
             JOIN dosen d ON d.nidn=c.nidn
             LEFT JOIN krs k ON k.id_kelas=c.id_kelas
             GROUP BY c.id_kelas ORDER BY c.id_kelas"
        )->fetch_all(MYSQLI_ASSOC);
        $programSummary = $conn->query(
            'SELECT p.id_prodi,p.nama_prodi,COUNT(m.nim) AS jumlah
             FROM prodi p LEFT JOIN mahasiswa m ON m.id_prodi=p.id_prodi
             GROUP BY p.id_prodi ORDER BY p.nama_prodi'
        )->fetch_all(MYSQLI_ASSOC);
    } elseif ($user['role'] === 'dosen') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM krs k JOIN mahasiswa m ON m.nim = k.nim WHERE m.nidn_wali = ? AND k.status = 'Menunggu'");
        $stmt->bind_param('s', $user['user_id']);
        $stmt->execute();
        $summary['menunggu'] = (int) $stmt->get_result()->fetch_assoc()['n'];
    } else {
        $term = api_current_term($conn);
        $summary = ['jumlah' => 0, 'sks' => 0];
        if ($term) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS jumlah,COALESCE(SUM(mk.sks),0) AS sks FROM krs k JOIN kelas c ON c.id_kelas=k.id_kelas JOIN mata_kuliah mk ON mk.kode_mk=c.kode_mk WHERE k.nim=? AND k.status<>'Ditolak' AND c.tahun_ajaran=? AND c.semester=?");
            $stmt->bind_param('sss', $user['user_id'], $term['tahun_ajaran'], $term['semester']);
            $stmt->execute();
            $summary = $stmt->get_result()->fetch_assoc();
            $summary['jumlah'] = (int) $summary['jumlah'];
            $summary['sks'] = (int) $summary['sks'];
        }
    }

    api_ok([
        'user' => $user + ['nama' => $name],
        'csrf' => $_SESSION['csrf'] ??= bin2hex(random_bytes(32)),
        'summary' => $summary,
        'class_summary' => $classSummary,
        'program_summary' => $programSummary,
    ]);
});
