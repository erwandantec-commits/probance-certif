# Guía del owner

Esta guía cubre todo lo que puedes hacer como owner de un programa: supervisar sesiones, gestionar certificaciones, importar y traducir preguntas, administrar packs y gestionar tu equipo.

Todos los datos que ves están limitados a tu programa. Si no tienes acceso a algo descrito aquí, contacta al administrador.

---

## Tu rol

Como owner, gestionas tu programa de principio a fin. Puedes:

- supervisar las sesiones de tus candidatos
- seguir y gestionar las certificaciones
- importar preguntas, actualizarlas y traducirlas
- consultar el estado de los packs
- gestionar los miembros de tu equipo

No puedes modificar los parámetros de un pack, crear o eliminar packs, gestionar los programas en sí, ni acceder a la configuración global.

---

## Sesiones

La página `Sesiones` lista todos los intentos de examen realizados en tu programa.

### Lo que ves

Para cada sesión:

- el candidato (nombre y correo)
- el pack realizado
- el tipo: `EXAM` (certificación) o `TRAINING` (entrenamiento)
- la puntuación obtenida
- el estado: en curso, finalizado, expirado
- el resultado: aprobado o reprobado
- un enlace al detalle completo

### Filtros disponibles

Puedes filtrar por correo del candidato, tipo de sesión, pack, estado o resultado. Los filtros se combinan.

### Detalle de una sesión

Al hacer clic en una sesión accedes a:

- la lista de preguntas realizadas durante ese intento
- las respuestas elegidas por el candidato
- las respuestas correctas resaltadas
- un enlace al perfil del candidato

### Exportación CSV

Un botón en la parte superior de la lista te permite exportar todas las sesiones que coinciden con los filtros activos en formato CSV, listo para Excel o cualquier hoja de cálculo.

---

## Certificaciones

La página `Certificaciones` proporciona una vista de seguimiento por candidato.

### Lo que ves

Para cada fila:

- nombre y correo del candidato
- pack correspondiente
- estado: `Certificado`, `Próximo a expirar` (menos de 30 días), `Expirado`, `Revocado`
- fecha del último aprobado
- fecha de expiración
- enlace a la última sesión de certificación

### Exportación CSV

Misma lógica que las sesiones: puedes exportar la vista filtrada.

### Revocar o restaurar una certificación

Desde el detalle de una certificación, puedes:

- **Revocar**: cancela la certificación activa de un candidato (deberá volver a examinarse)
- **Restaurar**: cancela una revocación si se hizo por error

---

## Preguntas

La página `Preguntas` te da acceso al banco de preguntas de tu programa.

### Lo que ves

Para cada pregunta:

- su identificador y texto
- su necesidad (`need`) y nivel si aplica
- su tipo: opción única, opción múltiple, verdadero/falso
- las opciones de respuesta (A a F)
- los packs a los que pertenece

### Filtros

Puedes filtrar por necesidad, nivel o combinación necesidad:nivel. Un gráfico de distribución muestra el desglose de tu banco por necesidad y nivel.

---

## Importación de preguntas

La página `Importar preguntas` es la herramienta principal para alimentar y mantener tu banco de preguntas.

### Archivo aceptado

CSV o Excel (`.csv`, `.xlsx`). El delimitador CSV se detecta automáticamente (coma, punto y coma o tabulación).

### Los tres modos de importación

#### Modo Fuente (creación)

Crea nuevas preguntas en tu programa a partir del archivo. Cada fila se convierte en una pregunta.

Columnas esperadas (sin distinción de mayúsculas, guiones y espacios ignorados):

- `id_externo` o `external_id` — identificador único de negocio de la pregunta
- `pregunta` o `texto` — enunciado de la pregunta
- `correcta` o `correct` — letra(s) de la respuesta correcta (ej. `A` o `A,C`)
- `opcion_a` a `opcion_f` — etiquetas de respuesta (mínimo `opcion_a` a `opcion_c`)
- `necesidad` o `need` — categoría de la pregunta (opcional)
- `nivel` o `level` — nivel de dificultad (opcional)
- `tipo` — `SINGLE`, `MULTI` o `TRUE_FALSE` (detectado automáticamente si está ausente)

Las preguntas existentes con el mismo `id_externo` se omiten, no se duplican.

#### Modo Actualización

Actualiza preguntas ya presentes en tu banco. Solo se modifican las columnas presentes en el archivo. Las preguntas no incluidas en el archivo no se tocan.

Usa el mismo formato que el modo Fuente. La columna `id_externo` es obligatoria para identificar qué pregunta actualizar.

#### Modo Traducción

Añade o actualiza traducciones de un lote de preguntas. El archivo debe contener el identificador de la pregunta fuente y las columnas de traducción para el idioma destino.

Columnas esperadas:

- `id_externo` — identificador de la pregunta original
- `texto_[idioma]` o `pregunta_[idioma]` — texto traducido (ej. `texto_en`, `pregunta_jp`)
- `opcion_a_[idioma]` a `opcion_f_[idioma]` — opciones traducidas

Los idiomas destino disponibles dependen del idioma fuente configurado en tu programa. Por ejemplo, si la fuente es `fr`, los idiomas destino son `en`, `es` y `jp`.

### Flujo de importación

1. Selecciona el modo (Fuente / Actualización / Traducción)
2. Carga tu archivo
3. La herramienta detecta automáticamente las columnas y propone un mapeo
4. Ajusta el mapeo si es necesario
5. Lanza la importación — un informe muestra las filas creadas, actualizadas, omitidas y con error

### FAQ

#### Si reimporto una pregunta con el mismo ID, ¿qué ocurre con las traducciones?

Si la pregunta se reimporta en el mismo programa con el mismo ID externo, la importación encuentra la misma pregunta interna. La traducción del enunciado sigue vinculada a la pregunta, pero se marca como obsoleta porque la fuente ha sido modificada.

Las respuestas, en cambio, se eliminan y se recrean durante una reinicialización o actualización. Las traducciones de las respuestas se eliminan y deberán reimportarse en modo Traducción.

---

## Traducciones de preguntas

La página `Traducciones` te ofrece una vista matricial del estado de traducción de todas tus preguntas.

### Lo que ves

Una tabla con una fila por pregunta y una columna por idioma destino. Cada celda muestra el estado de la traducción:

- **Completa** — la traducción está al día respecto a la fuente
- **Obsoleta** — la pregunta fuente fue modificada desde la última traducción
- **Parcial** — algunos campos están traducidos pero no todos
- **Faltante** — no existe ninguna traducción para este idioma

Contadores en la parte superior muestran la cobertura global por idioma.

### Filtros

Puedes filtrar por pack, necesidad, idioma o estado de traducción.

### Cómo completar las traducciones

Dos formas:

1. **Importación de archivo**: usa el modo `Traducción` de la página de importación (ver arriba)
2. **Edición manual**: haz clic en una pregunta de la lista para abrir su formulario de edición e introducir las traducciones directamente

---

## Packs

La página `Packs` lista los packs de certificación disponibles en tu programa.

### Lo que ves

Para cada pack:

- su nombre
- el número de preguntas disponibles vs el número requerido por sesión
- el desglose por necesidad y nivel si las reglas de selección están definidas

### Lo que puedes hacer

- **Reordenar**: usa las flechas arriba/abajo para cambiar el orden de visualización en el espacio candidato
- **Activar / Desactivar**: un pack desactivado ya no se muestra a los candidatos

No puedes modificar los parámetros de un pack (umbral, duración, espera…) ni eliminarlo. Contacta al administrador para este tipo de cambios.

---

## Usuarios

La página `Usuarios` te permite gestionar las cuentas de tu equipo.

### Lo que ves

La lista de usuarios vinculados a tu programa (mediante su acceso de programa `USER` u `OWNER`).

Para cada usuario:

- nombre, correo, rol
- número de sesiones realizadas
- número de exámenes aprobados
- fecha de la última sesión

### Lo que puedes hacer

**Crear una cuenta** — introduce correo, nombre, apellido, contraseña y rol. Puedes asignar `USER` (candidato estándar) y `OWNER` (co-gestor).

**Modificar una cuenta** — desde el perfil del usuario: nombre, apellido, correo, contraseña, rol de programa. No puedes editar cuentas que tienen el rol `ADMIN` a nivel de sistema.

**Eliminar una cuenta** — posible para cuentas `USER` y `OWNER` dentro de tu ámbito.

### Roles de programa

Desde el perfil de un usuario, puedes establecer su rol de acceso a tu programa:

- `USER` — puede realizar exámenes en el espacio candidato
- `OWNER` — co-gestor del programa, accede al área de administración con los mismos derechos que tú

### Perfil del candidato

Al hacer clic en un usuario accedes a su perfil completo: información de cuenta, historial de sesiones, estado de certificaciones.
