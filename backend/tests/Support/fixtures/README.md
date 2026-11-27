# Llaves RSA SOLO para pruebas (tests/Support/GoogleTokens.php)

Firman id_token falsos "de Google" en las pruebas de POST /auth/google. No se usan en ningún entorno real ni
firman nada fuera de las pruebas; no son secretos. Se regeneran con:

    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out google_prueba.pem
