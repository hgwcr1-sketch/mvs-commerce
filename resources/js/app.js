import '../css/app.css';

import * as MvsOffline from './offline/index.js';
import './offline/cold-start.js';

import Alpine from 'alpinejs';
import qz from 'qz-tray';

import './modules/clientes';
import './modules/compras';
import './mvs-print/qz';
import './navigation';
import './scanner';
import './tabs';
import './transfers';

window.Alpine = Alpine;
window.qz = qz;
window.MvsOffline = MvsOffline;

Alpine.start();
