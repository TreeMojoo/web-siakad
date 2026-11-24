<?php
require_once __DIR__ . '/../config/api.php';

api_run(function () use ($conn): void {
    api_role(['admin']);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');

    if ($method === 'GET') {
        $search = $_GET['q'] ?? '';
        if (!is_string($search)) throw new ApiException('Parameter pencarian tidak valid.');
        $search = trim($search);
        $stmt = $conn->prepare(
            'SELECT m.nim, m.nama_mhs, m.jenis_kelamin, m.tgl_lahir, m.alamat, m.angkatan,
                    m.id_prodi, p.nama_prodi, m.nidn_wali, d.nama_dosen
             FROM mahasiswa m JOIN prodi p ON p.id_prodi = m.id_prodi
             JOIN dosen d ON d.nidn = m.nidn_wali
             WHERE (? = \'\' OR m.nim LIKE CONCAT(\'%\', ?, \'%\')
                    OR m.nama_mhs LIKE CONCAT(\'%\', ?, \'%\')
                    OR p.nama_prodi LIKE CONCAT(\'%\', ?, \'%\'))
             ORDER BY m.nim'
        );
        $stmt->bind_param('ssss', $search, $search, $search, $search);
        $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $prodi = $conn->query('SELECT id_prodi, nama_prodi FROM prodi ORDER BY nama_prodi')->fetch_all(MYSQLI_ASSOC);
        $dosen = $conn->query('SELECT nidn, nama_dosen FROM dosen ORDER BY nama_dosen')->fetch_all(MYSQLI_ASSOC);
        api_ok(['mahasiswa' => $students, 'prodi' => $prodi, 'dosen' => $dosen]);
        return;
    }

    api_method('POST');
    $data = api_json();
    api_csrf($data);
    $action = is_string($data['action'] ?? null) ? $data['action'] : '';

    if ($action === 'delete') {
        $nim = api_text($data, 'nim', 20);
        $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM krs WHERE nim = ?');
        $stmt->bind_param('s', $nim);
        $stmt->execute();
        if ((int) $stmt->get_result()->fetch_assoc()['n'] > 0) {
            throw new ApiException('Mahasiswa memiliki riwayat KRS dan tidak dapat dihapus.');
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("DELETE FROM users WHERE role = 'mhs' AND user_id = ?");
            $stmt->bind_param('s', $nim);
            $stmt->execute();
            $stmt = $conn->prepare('DELETE FROM mahasiswa WHERE nim = ?');
            $stmt->bind_param('s', $nim);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new ApiException('Mahasiswa tidak ditemukan.', 404);
            }
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
        api_ok(['message' => 'Data mahasiswa dihapus.']);
        return;
    }

    if (!in_array($action, ['create', 'update'], true)) {
        throw new ApiException('Aksi mahasiswa tidak valid.');
    }
    $nim = api_text($data, 'nim', 10);
    $nama = api_text($data, 'nama_mhs', 80);
    $jk = is_string($data['jenis_kelamin'] ?? null) ? $data['jenis_kelamin'] : '';
    $angkatan = filter_var($data['angkatan'] ?? null, FILTER_VALIDATE_INT);
    $prodi = api_text($data, 'id_prodi', 3);
    $wali = api_text($data, 'nidn_wali', 10);
    $tgl = api_text($data, 'tgl_lahir', 10, false);
    $alamat = api_text($data, 'alamat', 100, false);
    if (!in_array($jk, ['L', 'P'], true) || !$angkatan || $angkatan < 1901 || $angkatan > 2155) {
        throw new ApiException('Jenis kelamin atau angkatan tidak valid.');
    }
    $birthDate = $tgl === '' ? false : DateTime::createFromFormat('!Y-m-d', $tgl);
    if ($tgl === '' || !$birthDate || $birthDate->format('Y-m-d') !== $tgl) {
        throw new ApiException('Tanggal lahir tidak valid.');
    }
    $tglValue = $tgl === '' ? null : $tgl;
    $stmt = $conn->prepare('SELECT 1 FROM prodi WHERE id_prodi=?');
    $stmt->bind_param('s', $prodi);
    $stmt->execute();
    if (!$stmt->get_result()->num_rows) {
        throw new ApiException('Program studi tidak ditemukan.');
    }
    $stmt = $conn->prepare('SELECT 1 FROM dosen WHERE nidn=?');
    $stmt->bind_param('s', $wali);
    $stmt->execute();
    if (!$stmt->get_result()->num_rows) {
        throw new ApiException('Dosen wali tidak ditemukan.');
    }

    if ($action === 'create') {
        $stmt = $conn->prepare('SELECT 1 FROM mahasiswa WHERE nim = ?');
        $stmt->bind_param('s', $nim);
        $stmt->execute();
        if ($stmt->get_result()->num_rows) {
            throw new ApiException('NIM sudah terdaftar.');
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('INSERT INTO mahasiswa (nim,nama_mhs,jenis_kelamin,tgl_lahir,alamat,angkatan,id_prodi,nidn_wali) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->bind_param('sssssiss', $nim, $nama, $jk, $tglValue, $alamat, $angkatan, $prodi, $wali);
            $stmt->execute();
            $hash = password_hash($nim, PASSWORD_DEFAULT);
            $role = 'mhs';
            $stmt = $conn->prepare('INSERT INTO users (username,password,role,user_id) VALUES (?,?,?,?)');
            $stmt->bind_param('ssss', $nim, $hash, $role, $nim);
            $stmt->execute();
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            if ($exception instanceof mysqli_sql_exception && $exception->getCode() === 1062) {
                throw new ApiException('NIM atau username sudah digunakan.');
            }
            throw $exception;
        }
        api_ok(['message' => 'Mahasiswa ditambahkan. Akun dibuat dengan username NIM dan password awal NIM.'], 201);
        return;
    }

    $stmt = $conn->prepare('UPDATE mahasiswa SET nama_mhs=?,jenis_kelamin=?,tgl_lahir=?,alamat=?,angkatan=?,id_prodi=?,nidn_wali=? WHERE nim=?');
    $stmt->bind_param('ssssisss', $nama, $jk, $tglValue, $alamat, $angkatan, $prodi, $wali, $nim);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        $stmt = $conn->prepare('SELECT 1 FROM mahasiswa WHERE nim = ?');
        $stmt->bind_param('s', $nim);
        $stmt->execute();
        if (!$stmt->get_result()->num_rows) {
            throw new ApiException('Mahasiswa tidak ditemukan.', 404);
        }
    }
    api_ok(['message' => 'Data mahasiswa diperbarui.']);
});
