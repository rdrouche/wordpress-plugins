/**
 * logger.js  (ESM)
 */
import fs   from 'fs';
import path  from 'path';

const LEVELS = { debug: 0, info: 1, warn: 2, error: 3 };
const COLORS = {
  debug: '\x1b[36m',
  info:  '\x1b[32m',
  warn:  '\x1b[33m',
  error: '\x1b[31m',
  reset: '\x1b[0m',
};

const LOG_LEVEL = process.env.LOG_LEVEL || 'info';
const LOG_FILE  = process.env.LOG_FILE  || '/logs/ccss.log';
const IS_DEV    = (process.env.NODE_ENV || 'development') === 'development';

const logDir = path.dirname(LOG_FILE);
if (!fs.existsSync(logDir)) fs.mkdirSync(logDir, { recursive: true });

let logStream = null;
try {
  logStream = fs.createWriteStream(LOG_FILE, { flags: 'a' });
} catch (e) {
  console.warn(`[LOGGER] Impossible d'ouvrir ${LOG_FILE} :`, e.message);
}

function log(level, message, meta = {}) {
  if (LEVELS[level] < LEVELS[LOG_LEVEL]) return;
  const ts    = new Date().toISOString();
  const entry = { ts, level: level.toUpperCase(), message, ...meta };

  if (IS_DEV) {
    const col     = COLORS[level] || '';
    const rst     = COLORS.reset;
    const metaStr = Object.keys(meta).length ? '  ' + JSON.stringify(meta) : '';
    console.log(`${col}[${level.toUpperCase()}]${rst} ${ts}  ${message}${metaStr}`);
  } else {
    console.log(JSON.stringify(entry));
  }
  if (logStream) logStream.write(JSON.stringify(entry) + '\n');
}

export const logger = {
  debug: (msg, meta) => log('debug', msg, meta),
  info:  (msg, meta) => log('info',  msg, meta),
  warn:  (msg, meta) => log('warn',  msg, meta),
  error: (msg, meta) => log('error', msg, meta),
};
