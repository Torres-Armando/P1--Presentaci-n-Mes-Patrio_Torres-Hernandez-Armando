<?php
include("conexion.php");

// ---------- Funciones de ayuda ----------
function e($texto) {                       // escribe texto de forma segura en el HTML
    return htmlspecialchars((string) $texto, ENT_QUOTES, "UTF-8");
}
function dinero($numero) {                 // 1899 -> $1,899.00 MXN
    return "$" . number_format($numero, 2) . " MXN";
}
function aviso($texto, $tipo = "ok") {     // guarda un mensaje para mostrarlo después
    $_SESSION["aviso"] = [$tipo, $texto];
}
function volver($seccion) {                // regresa a la página, a la sección indicada
    header("Location: index.php#" . $seccion);
    exit;
}

$usuario_id = $_SESSION["usuario_id"] ?? 0;

// ---------- Acciones de los formularios ----------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = $_POST["accion"] ?? "";

    // REGISTRO
    if ($accion === "registro") {
        $nombre   = trim($_POST["nombre"] ?? "");
        $correo   = trim($_POST["correo"] ?? "");
        $password = $_POST["password"] ?? "";

        if ($nombre === "" || !filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
            aviso("Revisa tus datos: escribe tu nombre, un correo válido y una contraseña de 6 caracteres o más.", "error");
            volver("cuenta");
        }

        $buscar = $conexion->prepare("SELECT id FROM usuarios WHERE correo = ?");
        $buscar->bind_param("s", $correo);
        $buscar->execute();
        $buscar->store_result();
        $existe = $buscar->num_rows > 0;
        $buscar->close();

        if ($existe) {
            aviso("Ese correo ya está registrado. Inicia sesión.", "error");
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);   // contraseña encriptada
            $guardar = $conexion->prepare("INSERT INTO usuarios (nombre, correo, password) VALUES (?, ?, ?)");
            $guardar->bind_param("sss", $nombre, $correo, $hash);
            $guardar->execute();
            $guardar->close();
            aviso("¡Registro exitoso! Ya puedes iniciar sesión.");
        }
        volver("cuenta");
    }

    // INICIAR SESIÓN
    if ($accion === "login") {
        $correo   = trim($_POST["correo"] ?? "");
        $password = $_POST["password"] ?? "";

        $buscar = $conexion->prepare("SELECT id, nombre, password FROM usuarios WHERE correo = ?");
        $buscar->bind_param("s", $correo);
        $buscar->execute();
        $buscar->bind_result($id, $nombre, $hash);
        $encontrado = $buscar->fetch();
        $buscar->close();

        if ($encontrado && password_verify($password, $hash)) {
            session_regenerate_id(true);
            $_SESSION["usuario_id"] = $id;
            $_SESSION["nombre"] = $nombre;
            aviso("¡Bienvenido de nuevo, " . $nombre . "!");
            volver("catalogo");
        }
        aviso("Correo o contraseña incorrectos.", "error");
        volver("cuenta");
    }

    // CERRAR SESIÓN
    if ($accion === "salir") {
        $_SESSION = [];
        session_regenerate_id(true);
        aviso("Cerraste sesión. ¡Vuelve pronto!");
        volver("inicio");
    }

    // Las acciones del carrito necesitan haber iniciado sesión
    $acciones_carrito = ["agregar", "mas", "menos", "quitar", "vaciar", "finalizar"];
    if (in_array($accion, $acciones_carrito)) {
        if (!$usuario_id) {
            aviso("Inicia sesión para usar tu carrito.", "error");
            volver("cuenta");
        }
        $producto_id = (int) ($_POST["producto_id"] ?? 0);

        if ($accion === "agregar") {
            // Revisamos que el balón exista y lo sumamos al carrito
            $revisar = $conexion->prepare("SELECT nombre FROM productos WHERE id = ?");
            $revisar->bind_param("i", $producto_id);
            $revisar->execute();
            $revisar->bind_result($nombre_producto);
            $existe = $revisar->fetch();
            $revisar->close();

            if ($existe) {
                $sumar = $conexion->prepare("INSERT INTO carrito (usuario_id, producto_id, cantidad) VALUES (?, ?, 1)
                                             ON DUPLICATE KEY UPDATE cantidad = cantidad + 1");
                $sumar->bind_param("ii", $usuario_id, $producto_id);
                $sumar->execute();
                $sumar->close();
                aviso($nombre_producto . " se agregó a tu carrito.");
            }
            volver("carrito");
        }

        if ($accion === "mas") {
            $q = $conexion->prepare("UPDATE carrito SET cantidad = cantidad + 1 WHERE usuario_id = ? AND producto_id = ?");
            $q->bind_param("ii", $usuario_id, $producto_id);
            $q->execute();
            $q->close();
            volver("carrito");
        }

        if ($accion === "menos") {
            $q = $conexion->prepare("UPDATE carrito SET cantidad = cantidad - 1 WHERE usuario_id = ? AND producto_id = ?");
            $q->bind_param("ii", $usuario_id, $producto_id);
            $q->execute();
            $q->close();
            // Si llegó a cero, se quita del carrito
            $q = $conexion->prepare("DELETE FROM carrito WHERE usuario_id = ? AND cantidad <= 0");
            $q->bind_param("i", $usuario_id);
            $q->execute();
            $q->close();
            volver("carrito");
        }

        if ($accion === "quitar") {
            $q = $conexion->prepare("DELETE FROM carrito WHERE usuario_id = ? AND producto_id = ?");
            $q->bind_param("ii", $usuario_id, $producto_id);
            $q->execute();
            $q->close();
            volver("carrito");
        }

        if ($accion === "vaciar") {
            $q = $conexion->prepare("DELETE FROM carrito WHERE usuario_id = ?");
            $q->bind_param("i", $usuario_id);
            $q->execute();
            $q->close();
            aviso("Tu carrito quedó vacío.");
            volver("carrito");
        }

        if ($accion === "finalizar") {
            // Total = suma de (cantidad x precio) de lo que hay en el carrito
            $q = $conexion->prepare("SELECT SUM(c.cantidad * p.precio) FROM carrito c
                                     JOIN productos p ON p.id = c.producto_id WHERE c.usuario_id = ?");
            $q->bind_param("i", $usuario_id);
            $q->execute();
            $q->bind_result($total);
            $q->fetch();
            $q->close();

            if (!$total) {
                aviso("Tu carrito está vacío.", "error");
                volver("carrito");
            }

            $q = $conexion->prepare("INSERT INTO pedidos (usuario_id, total) VALUES (?, ?)");
            $q->bind_param("id", $usuario_id, $total);
            $q->execute();
            $numero_pedido = $conexion->insert_id;
            $q->close();

            $q = $conexion->prepare("DELETE FROM carrito WHERE usuario_id = ?");
            $q->bind_param("i", $usuario_id);
            $q->execute();
            $q->close();

            aviso("¡Compra realizada! Tu pedido #" . $numero_pedido . " por " . dinero($total) . " quedó registrado.");
            volver("carrito");
        }
    }
}

// ---------- Datos para mostrar la página ----------
$aviso = $_SESSION["aviso"] ?? null;
unset($_SESSION["aviso"]);

$productos = $conexion->query("SELECT * FROM productos ORDER BY id")->fetch_all(MYSQLI_ASSOC);

$carrito = [];
$pedidos = [];
$total = 0;
$piezas = 0;

if ($usuario_id) {
    $q = $conexion->prepare("SELECT p.id, p.nombre, p.imagen, p.precio, c.cantidad
                             FROM carrito c JOIN productos p ON p.id = c.producto_id
                             WHERE c.usuario_id = ? ORDER BY c.id");
    $q->bind_param("i", $usuario_id);
    $q->execute();
    $carrito = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();

    foreach ($carrito as $fila) {
        $total += $fila["precio"] * $fila["cantidad"];
        $piezas += $fila["cantidad"];
    }

    $q = $conexion->prepare("SELECT id, total, fecha FROM pedidos WHERE usuario_id = ? ORDER BY id DESC LIMIT 5");
    $q->bind_param("i", $usuario_id);
    $q->execute();
    $pedidos = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tienda Molten México - PHP & MySQL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500&family=Marcellus&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="estilos.css">
</head>
<body>

    <header class="cabecera">
        <div class="barra">
            <a class="logo" href="#inicio">molten</a>
            <nav>
                <a href="#catalogo">Catálogo</a>
                <a href="#cuenta"><?php echo $usuario_id ? "Mi cuenta" : "Registro e inicio de sesión"; ?></a>
                <a href="#carrito">Carrito<?php if ($piezas) { ?> <span class="contador"><?php echo (int) $piezas; ?></span><?php } ?></a>
            </nav>
        </div>
    </header>

    <?php if ($aviso) { ?>
        <div class="aviso aviso-<?php echo e($aviso[0]); ?>" role="status">
            <?php echo e($aviso[1]); ?>
        </div>
    <?php } ?>

    <!-- PORTADA -->
    <section class="portada" id="inicio">
        <div class="barra portada-grid">
            <div>
                <h1>Para los verdaderos jugadores</h1>
                <p>Tienda oficial de balones Molten. Elige entre básquetbol, voleibol y fútbol.</p>
                <a class="btn-enviar boton" href="#catalogo">Ver los balones</a>
            </div>
            <div class="balones">
                <img src="futbol.jpg" alt="Balón de fútbol Molten Vantaggio">
                <img src="basket.jpg" alt="Balón de básquetbol Molten BG5000">
                <img src="voleibol.jpg" alt="Balón de voleibol Molten V5M5000">
            </div>
        </div>
    </section>

    <!-- PRODUCTOS -->
    <section class="seccion" id="catalogo">
        <div class="contenedor">
            <h2>Catálogo de Productos Molten</h2>
            <div class="galeria">
                <?php foreach ($productos as $p) { ?>
                    <article class="tarjeta-producto">
                        <div class="foto">
                            <img src="<?php echo e($p["imagen"]); ?>" alt="<?php echo e("Balón de " . mb_strtolower($p["deporte"])); ?>">
                        </div>
                        <div class="datos">
                            <span class="deporte"><?php echo e($p["deporte"]); ?></span>
                            <h3><?php echo e($p["nombre"]); ?></h3>
                            <p><?php echo e($p["descripcion"]); ?></p>
                            <p class="precio"><?php echo dinero($p["precio"]); ?></p>
                            <form method="POST" action="index.php">
                                <input type="hidden" name="accion" value="agregar">
                                <input type="hidden" name="producto_id" value="<?php echo (int) $p["id"]; ?>">
                                <button type="submit" class="btn-comprar">Agregar al carrito</button>
                            </form>
                        </div>
                    </article>
                <?php } ?>
            </div>
        </div>
    </section>

    <!-- REGISTRO E INICIO DE SESIÓN -->
    <section class="seccion" id="cuenta">
        <div class="contenedor">
            <?php if ($usuario_id) { ?>
                <h2>Mi cuenta</h2>
                <div class="panel sesion">
                    <p>Hola, <strong><?php echo e($_SESSION["nombre"]); ?></strong>. Tu sesión está iniciada y tu carrito se guarda en tu cuenta.</p>
                    <form method="POST" action="index.php">
                        <input type="hidden" name="accion" value="salir">
                        <button type="submit" class="btn-enviar">Cerrar sesión</button>
                    </form>
                </div>
            <?php } else { ?>
                <h2>Tu cuenta</h2>
                <p class="subtitulo">Para llenar tu carrito necesitas una cuenta. Regístrate o inicia sesión.</p>
                <div class="panel dos-columnas">
                    <div id="registro">
                        <h3>Registro de nuevo cliente</h3>
                        <form class="formulario" action="index.php" method="POST">
                            <input type="hidden" name="accion" value="registro">
                            <div class="campo">
                                <label for="nombre">Nombre completo</label>
                                <input type="text" id="nombre" name="nombre" required placeholder="Ej. Juan Pérez">
                            </div>
                            <div class="campo">
                                <label for="correo">Correo electrónico</label>
                                <input type="email" id="correo" name="correo" required placeholder="correo@ejemplo.com">
                            </div>
                            <div class="campo">
                                <label for="password">Contraseña (mínimo 6 caracteres)</label>
                                <input type="password" id="password" name="password" minlength="6" required>
                            </div>
                            <button type="submit" class="btn-enviar">Crear cuenta</button>
                        </form>
                    </div>

                    <div id="login">
                        <h3>Iniciar sesión</h3>
                        <form class="formulario" action="index.php" method="POST">
                            <input type="hidden" name="accion" value="login">
                            <div class="campo">
                                <label for="correo_login">Correo electrónico</label>
                                <input type="email" id="correo_login" name="correo" required>
                            </div>
                            <div class="campo">
                                <label for="pass_login">Contraseña</label>
                                <input type="password" id="pass_login" name="password" required>
                            </div>
                            <button type="submit" class="btn-enviar">Ingresar</button>
                        </form>
                    </div>
                </div>
            <?php } ?>
        </div>
    </section>

    <!-- SECCIÓN CARRITO -->
    <section class="seccion" id="carrito">
        <div class="contenedor">
            <h2>Tu carrito de compras</h2>

            <?php if (!$usuario_id) { ?>
                <div class="panel vacio">
                    <p>Inicia sesión para ver y llenar tu carrito.</p>
                    <a class="btn-enviar boton" href="#cuenta">Ir a mi cuenta</a>
                </div>
            <?php } elseif (count($carrito) == 0) { ?>
                <div class="panel vacio">
                    <p>Tu carrito está vacío. Agrega un balón del catálogo.</p>
                    <a class="btn-enviar boton" href="#catalogo">Ver los balones</a>
                </div>
            <?php } else { ?>
                <div class="tabla-caja">
                    <table>
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Cantidad</th>
                                <th>Precio unitario</th>
                                <th>Subtotal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($carrito as $fila) { ?>
                                <tr>
                                    <td>
                                        <div class="producto-fila">
                                            <img src="<?php echo e($fila["imagen"]); ?>" alt="">
                                            <span><?php echo e($fila["nombre"]); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cantidad">
                                            <form method="POST" action="index.php">
                                                <input type="hidden" name="producto_id" value="<?php echo (int) $fila["id"]; ?>">
                                                <button type="submit" name="accion" value="menos" class="mini" aria-label="Quitar uno">−</button>
                                            </form>
                                            <span><?php echo (int) $fila["cantidad"]; ?></span>
                                            <form method="POST" action="index.php">
                                                <input type="hidden" name="producto_id" value="<?php echo (int) $fila["id"]; ?>">
                                                <button type="submit" name="accion" value="mas" class="mini" aria-label="Agregar uno">+</button>
                                            </form>
                                        </div>
                                    </td>
                                    <td><?php echo dinero($fila["precio"]); ?></td>
                                    <td><?php echo dinero($fila["precio"] * $fila["cantidad"]); ?></td>
                                    <td>
                                        <form method="POST" action="index.php">
                                            <input type="hidden" name="producto_id" value="<?php echo (int) $fila["id"]; ?>">
                                            <button type="submit" name="accion" value="quitar" class="enlace">Quitar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <div class="resumen">
                    <form method="POST" action="index.php">
                        <input type="hidden" name="accion" value="vaciar">
                        <button type="submit" class="btn-borde">Vaciar carrito</button>
                    </form>
                    <div class="total">
                        <span>Total</span>
                        <strong><?php echo dinero($total); ?></strong>
                    </div>
                    <form method="POST" action="index.php">
                        <input type="hidden" name="accion" value="finalizar">
                        <button type="submit" class="btn-comprar">Finalizar compra</button>
                    </form>
                </div>
            <?php } ?>

            <?php if (count($pedidos) > 0) { ?>
                <h3 class="titulo-pedidos">Tus compras recientes</h3>
                <div class="tabla-caja">
                    <table>
                        <thead>
                            <tr><th>Pedido</th><th>Fecha</th><th>Total</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pedidos as $ped) { ?>
                                <tr>
                                    <td>#<?php echo (int) $ped["id"]; ?></td>
                                    <td><?php echo e($ped["fecha"]); ?></td>
                                    <td><?php echo dinero($ped["total"]); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        </div>
    </section>

    <footer>
        <p>Torres Hernández Armando | Tienda Molten | 5°J | 0901</p>
    </footer>

</body>
</html>
