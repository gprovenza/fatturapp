<?php
require_once 'db.php';

// MODIFICA QUESTI DATI
$username = "admin";
$password = "password_sicura_da_cambiare";
$email = "tua@email.it";

$conn = getDBConnection();

$password_hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = mysqli_prepare($conn, "INSERT INTO tb_utenti (username, password_hash, email) VALUES (?, ?, ?)");
mysqli_stmt_bind_param($stmt, "sss", $username, $password_hash, $email);

if (mysqli_stmt_execute($stmt)) {
    echo "Utente creato con successo!<br>";
    echo "Username: $username<br>";
    echo "Ora puoi eliminare questo file per sicurezza.";
} else {
    echo "Errore: " . mysqli_error($conn);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
?>
