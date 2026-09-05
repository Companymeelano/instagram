// InstaPilot V25 early compatibility layer — must run before any legacy script uses $/$$.
window.IPQuery = window.IPQuery || function(selector, root){ return (root || document).querySelector(selector); };
window.IPQueryAll = window.IPQueryAll || function(selector, root){ return Array.prototype.slice.call((root || document).querySelectorAll(selector)); };
window.$ = window.$ || window.IPQuery;
window.$$ = window.$$ || window.IPQueryAll;

