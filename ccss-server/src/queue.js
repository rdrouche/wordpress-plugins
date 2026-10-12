/**
 * queue.js  (ESM)
 * ─────────
 * File d'attente pour limiter le nb de Chromium simultanés.
 */
import { logger } from './logger.js';

export class Queue {
  constructor({ concurrency = 2 } = {}) {
    this.concurrency = concurrency;
    this._running    = 0;
    this._queue      = [];
  }

  add(task) {
    return new Promise((resolve, reject) => {
      this._queue.push({ task, resolve, reject });
      logger.debug(`📋 File : +1 tâche (file=${this._queue.length}, actives=${this._running})`);
      this._run();
    });
  }

  size() { return this._queue.length; }

  _run() {
    while (this._running < this.concurrency && this._queue.length > 0) {
      const { task, resolve, reject } = this._queue.shift();
      this._running++;
      logger.debug(`▶️  Démarrage tâche (actives=${this._running}, restantes=${this._queue.length})`);
      task()
        .then(resolve)
        .catch(reject)
        .finally(() => {
          this._running--;
          logger.debug(`⏹️  Tâche terminée (actives=${this._running}, restantes=${this._queue.length})`);
          this._run();
        });
    }
  }
}
