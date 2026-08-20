# Guía del owner

¡Bienvenido! Esta guía te ayuda a familiarizarte con tu rol de owner: cómo está organizada la herramienta, qué hacer nada más asumir el rol, y luego el detalle de cada pantalla para consultar sobre la marcha.

Todos los datos que ves están limitados a tu programa. Si no tienes acceso a algo descrito aquí, contacta al administrador.

---

## Entender la organización de la herramienta

Antes de empezar, así es como se relacionan los elementos que vas a gestionar:

- **Programa** — es tu ámbito. Todo lo que gestionas (packs, preguntas, candidatos, sesiones) pertenece a tu programa. Nunca ves los datos de otro programa.
- **Pack** — una certificación ofrecida a tus candidatos (ej. "Certificación Ventas nivel 1"). Un pack define una duración, un umbral de aprobación y unas reglas de selección que determinan qué preguntas se plantean en el examen.
- **Pregunta** — un elemento del banco, asociado a una necesidad (categoría) y un nivel. Las reglas de selección de un pack extraen de este banco.
- **Sesión** — un intento de examen (`EXAM`) o de entrenamiento (`TRAINING`) por parte de un candidato.
- **Certificación** — el resultado consolidado: un candidato certificado en un pack, con una fecha de expiración.

En resumen: **Programa → Packs → Preguntas → Sesiones → Certificaciones**. Un pack necesita suficientes preguntas que cubran sus reglas de selección para poder utilizarse.

### Lo que puedes hacer

- supervisar sesiones y gestionar certificaciones de tu programa
- importar, actualizar y traducir preguntas
- consultar el estado de los packs (Listo / Incompleto / Sin reglas de selección)
- crear, modificar y eliminar cuentas `USER` y `OWNER` de tu programa

### Lo que corresponde al administrador

- crear o eliminar packs, modificar sus parámetros (umbral, duración, reglas de selección)
- crear o eliminar programas
- acceder a la configuración global de la aplicación

En cuanto te falte un permiso, la solución es casi siempre la misma: contactar al administrador.

---

## Primeros pasos: ¿qué hacer al convertirte en owner?

Este es el orden recomendado para tu primera visita al espacio de administración.

### 1. Revisar el estado de los packs de tu programa

Ve a la pestaña **Packs** y mira la insignia de cada pack:

| Insignia | Qué significa | Qué debes hacer |
|---|---|---|
| **Listo** | El pack está listo para usarse | Nada, pasa al siguiente paso |
| **Incompleto** | Las reglas de selección están definidas pero el banco de preguntas no las cubre | Importar preguntas (paso 2) |
| **Sin reglas de selección** | Nadie ha configurado aún reglas de selección en este pack | Contacta al administrador — solo él configura las reglas de selección, la duración y el umbral de un pack |

Nunca compartas accesos con tus candidatos mientras un pack no muestre **Listo**.

### 2. Revisar / completar el banco de preguntas

Ve a la pestaña **Preguntas** para ver lo que ya existe, filtrado por necesidad y nivel, y detectar lo que falta respecto a las reglas de selección de tus packs.

Si faltan preguntas, usa **Importación de preguntas** (modo *Fuente*) con un archivo CSV o Excel — consulta la sección **Importación de preguntas** más abajo para el formato esperado.

### 3. Traducir si tu programa es multilingüe

Si tus candidatos no hablan todos el idioma fuente de tu programa, ve a la pestaña **Traducciones** para ver la cobertura por idioma y completar lo que falte (importación de archivo o edición manual).

### 4. Crear las cuentas de tu equipo

Ve a la pestaña **Usuarios** y crea una cuenta para cada candidato (rol `USER`). Si otra persona debe co-gestionar el programa contigo, asígnale el rol `OWNER`.

### 5. Hacer una prueba antes de compartir los accesos

Antes de invitar a tus candidatos, verifica tú mismo (o con una cuenta `USER` de prueba) que un intento se desarrolla como se espera: número correcto de preguntas, idioma correcto, umbral de aprobación coherente.

### 6. Compartir los accesos y seguir los resultados

Una vez verificado, envía las credenciales a tus candidatos. Luego usa las pestañas **Sesiones**, **Certificaciones** y **Análisis** para seguir la actividad sobre la marcha (detalle de cada una más abajo).

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
- **A revisar** — la pregunta fuente fue modificada desde la última traducción
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
- la duración y el umbral de aprobación
- el número de preguntas extraídas por sesión (definido por las reglas de selección)
- su estado de configuración

### Estados de un pack

| Badge | Significado |
|---|---|
| **Listo** | El pack está listo; las preguntas en la base cubren las reglas de selección |
| **Incompleto** | Las reglas de selección están configuradas pero no hay suficientes preguntas en la base |
| **Sin reglas de selección** | Ninguna regla de selección configurada — el pack no puede utilizarse |

Un pack con estado **Sin reglas de selección** o **Incompleto** no funcionará correctamente para los candidatos.

### Lo que puedes hacer

- **Reordenar**: usa las flechas arriba/abajo para cambiar el orden de visualización en el espacio candidato
- **Activar / Desactivar**: un pack desactivado ya no se muestra a los candidatos

No puedes modificar los parámetros de un pack (umbral, duración, reglas de selección…) ni eliminarlo. Contacta al administrador para este tipo de cambios.

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

---

## Análisis

La página `Análisis` ofrece una vista agregada del rendimiento por pregunta en todas las sesiones de tu programa.

### Lo que ves

Una barra de estadísticas globales se muestra en la parte superior:

- **Preguntas** — número de preguntas distintas que han recibido al menos una respuesta
- **Respuestas** — número total de respuestas registradas
- **Tasa de acierto / Tasa de error** — porcentajes globales sobre todas las sesiones filtradas
- **Sin cambios / Modificadas / Eliminadas** — estado de las preguntas respecto a su versión durante las sesiones

### Filtros disponibles

**Alcance:**

- tipo de sesión (Examen, Entrenamiento o ambos)
- paquete
- categoría de pregunta
- rango de fechas (fecha de inicio de sesión)
- ID externo de la pregunta
- estado de la pregunta (Sin cambios, Modificada, Eliminada)

**Métricas avanzadas:** puedes filtrar por tasa de acierto, tasa de error o número de respuestas con comparadores (`=`, `>=`, `<=`, `entre`). Ejemplo: preguntas con una tasa de error ≥ 60 % y al menos 10 respuestas.

### Tabla de preguntas

Cada fila representa una pregunta que recibió al menos una respuesta dentro de las sesiones filtradas.

- **Rango** — clasificación global por tasa de acierto descendente
- **Estado** — indica si la pregunta es idéntica a la vista por los candidatos, o si ha sido modificada/eliminada desde entonces
- **% OK / % KO** — tasas de acierto y error en el período filtrado
- Haz clic en los encabezados de columna para ordenar

### Zoom: sesiones de una pregunta

El botón de zoom abre el detalle de las sesiones que incluyeron la pregunta: fecha, candidato, paquete, tipo de sesión, resultado (OK / KO) y enlace a la sesión completa.

### Casos de uso típicos

- **Identificar preguntas problemáticas** — ordenar por `% KO` descendente para detectar las que se fallan con frecuencia
- **Encontrar preguntas poco usadas** — ordenar por número de respuestas ascendente
- **Supervisar el estado de las preguntas** — filtrar por `Modificada` o `Eliminada` tras una actualización de contenido
