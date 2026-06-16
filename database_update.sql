-- ─── EJECUTAR ESTE SQL EN TU BASE DE DATOS blufast ──────────────────────────

-- Nuevas columnas en usuarios
ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS bubble_color VARCHAR(20) DEFAULT '#18033B',
  ADD COLUMN IF NOT EXISTS chat_bg      VARCHAR(500) DEFAULT '',
  ADD COLUMN IF NOT EXISTS bio          TEXT DEFAULT '',
  ADD COLUMN IF NOT EXISTS nombre       VARCHAR(100) DEFAULT '';

-- Columna reply_to en mensajes
ALTER TABLE mensajes
  ADD COLUMN IF NOT EXISTS reply_to INT NULL DEFAULT NULL;

-- Tabla reacciones
CREATE TABLE IF NOT EXISTS reacciones (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  mensaje_id  INT NOT NULL,
  usuario_id  INT NOT NULL,
  emoji       VARCHAR(20) NOT NULL,
  fecha       DATETIME DEFAULT NOW(),
  UNIQUE KEY uq_react (mensaje_id, usuario_id)
);

-- Tabla bloqueados
CREATE TABLE IF NOT EXISTS bloqueados (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT NOT NULL,
  bloqueado_id INT NOT NULL,
  fecha       DATETIME DEFAULT NOW(),
  UNIQUE KEY uq_block (usuario_id, bloqueado_id)
);
