# Sistema de Inventario Patrimonial y Mantenimiento Preventivo / Correctivo — IESTP Utcubamba

Sistema web en **PHP 8 + MySQL/MariaDB + Bootstrap 5** adecuado al 100% a las exigencias del docente/ingeniero, al **Inventario General de Bienes Institucional (`storage/inventario.xlsx` con 40 hojas / 3,440 bienes)** y al formato estándar de la **Ficha de Registro de Mantenimiento de Equipo**.

---

## Instalación Rápida en XAMPP (1 solo paso de Base de Datos)

1. Copie la carpeta `mantenimiento_iestpu` dentro de `C:\xampp\htdocs\`.
2. Inicie **Apache** y **MySQL** en el panel de control de XAMPP.
3. Abra `http://localhost/phpmyadmin`, cree la base de datos **`bd_mantenimiento_iestpu`** (cotejamiento `utf8mb4_general_ci`) e importe el archivo todo-en-uno:
   - **`database/instalacion_completa_iestpu.sql`**
   *(Este archivo ya crea las tablas, vistas, roles, usuarios de prueba, las 39 áreas/laboratorios/oficinas, los **3,440 bienes del Excel**, los **147 movimientos**, los **913 planes y cronogramas preventivos** y los reportes de falla de ejemplo).*
4. Ingrese al sistema en: `http://localhost/mantenimiento_iestpu/public/login.php`

---

## Credenciales de Acceso Listas para Probar Todos los Roles

Todas las cuentas tienen la contraseña **`admin123`**:

| Rol en el Sistema | Correo Institucional | Contraseña | Qué visualiza y gestiona |
| :--- | :--- | :--- | :--- |
| **Administrador General** | `admin@iestpu.edu.pe` | `admin123` | Inventario total (3,440 bienes), buscador por código/serie, fichas técnicas, cronogramas y **Reportes Globales de todas las Áreas y Programas**. |
| **Coordinador de APSTI** | `coordinador.apsti@iestpu.edu.pe` | `admin123` | Inventario de **APSTI** (Lab. Cómputo I, Lab. Cómputo II, Sala de Ensamblaje, Aulas APSTI), fechas límite de mantenimiento, reporte de fallas (ej. impresora con líneas) y **visualización automática del cronograma preventivo**. |
| **Coordinador de Contabilidad** | `coordinador.cont@iestpu.edu.pe` | `admin123` | Inventario y reportes de **Contabilidad** (Lab. Cómputo Contabilidad, Unidad Académica y Aulas). |
| **Coordinador de Enfermería** | `coordinador.enfe@iestpu.edu.pe` | `admin123` | Inventario y reportes de **Enfermería Técnica** (Lab. Enfermería Técnica, Unidad Académica y Aulas). |
| **Coordinador de Agropecuaria** | `coordinador.agro@iestpu.edu.pe` | `admin123` | Inventario y reportes de **Producción Agropecuaria** (Lab. Biología y Química, Unidad Académica y Aulas). |
| **Coordinador Áreas Admin. / Dirección** | `coordinador.admin@iestpu.edu.pe` | `admin123` | Inventario y reportes de **Dirección General** (Laptops, Impresoras), Administración I y II, Tesorería, Logística, Almacén General, Biblioteca, Auditorio. |
| **Área de Mantenimiento / Soporte** | `soporte@iestpu.edu.pe` | `admin123` | Recibe los reportes de fallas de los Coordinadores, **verifica el equipo, registra el Diagnóstico Técnico, Estado de Visita, Servicio Completo/Pendiente y genera el Cronograma Preventivo** que el Coordinador ve automáticamente. |
| **Docente** | `docente@iestpu.edu.pe` | `admin123` | Buscador de bienes, reporte de fallas en aula/laboratorio y consulta de cronograma. |
