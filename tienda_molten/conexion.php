<?php
// Configuración de la conexión a MySQL (Servidor local como XAMPP)
$host = "localhost";
$usuario = "root";       // Usuario por defecto en XAMPP
$password = "";          // Sin contraseña por defecto en XAMPP
$base_datos = "tienda_molten";

// La sesión guarda quién inició sesión y los avisos entre páginas
session_start();

// Crear la conexión
$conexion = new mysqli($host, $usuario, $password, $base_datos);

// Verificar si hubo error en la conexión
if ($conexion->connect_error) {
    die("Error de conexión a la base de datos: " . $conexion->connect_error);
}

// Para que las letras con acento y la ñ se guarden bien
$conexion->set_charset("utf8mb4");
?>
