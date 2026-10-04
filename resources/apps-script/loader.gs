/**
 * SatelliteWP report — loader. Paste it once into the Google Doc
 * (Extensions → Apps Script); it does not change afterwards.
 *
 * The "Report data key" address pasted in the prompt names both the data and
 * the Manager serving it (PROD or DEV): the rendering engine is downloaded
 * from that same Manager with the same token, stored in this document per
 * Manager origin, and replaced only on confirmation when a newer version is
 * published.
 */

// This domain and its subdomains; any other host asks for confirmation first.
var TRUSTED_DOMAIN = 'satellitewp.com';

// A document property holds at most 9 KB and a character weighs up to 3 bytes
// in UTF-8: the engine is split into 2,000-character chunks.
var CHUNK_SIZE = 2000;
var KEY_PREFIX = 'engine|';

function onOpen() {
  DocumentApp.getUi()
    .createMenu('SatelliteWP report')
    .addItem('Fill from an extraction…', 'fillReport')
    .addItem('Check document variables…', 'checkVariables')
    .addSeparator()
    .addItem('Forget the stored engine', 'forgetEngine')
    .addToUi();
}

function fillReport() {
  run('fill', 'Report to import',
    'In Manager, on the extraction page, click "Report data key" ' +
    '(it copies an address) and paste it here.\n\nValid for one hour.');
}

function checkVariables() {
  run('check', 'Check document variables',
    'Paste a "Report data key" address: the document\'s {{variables}} are ' +
    'compared with what this extraction provides.');
}

function forgetEngine() {
  var properties = PropertiesService.getDocumentProperties();
  properties.getKeys().forEach(function (key) {
    if (key.indexOf(KEY_PREFIX) === 0) { properties.deleteProperty(key); }
  });
  DocumentApp.getUi().alert('Engine forgotten: the next import downloads the published version.');
}

function run(action, title, message) {
  var ui       = DocumentApp.getUi();
  var response = ui.prompt(title, message, ui.ButtonSet.OK_CANCEL);
  if (response.getSelectedButton() !== ui.Button.OK) { return; }

  var address = response.getResponseText().trim();
  var m = /^https:\/\/([a-z0-9.-]+)(:\d+)?(\/[^?#\s]*)?\/report\.json\?token=[A-Za-z0-9%_-]+$/i.exec(address);
  if (!m) {
    ui.alert('This does not look like a "Report data key" address.\n\n' +
      'Go back to Manager, click the button on the extraction page, ' +
      'then paste what was copied, as is.');
    return;
  }

  var host = m[1].toLowerCase();
  if (!isTrustedHost(host)) {
    var answer = ui.alert('Unknown domain',
      'Are you sure you want to load a script from "' + host + '"?\n\n' +
      'This script will be able to modify this document.', ui.ButtonSet.YES_NO);
    if (answer !== ui.Button.YES) { return; }
  }

  try {
    var engine = engineFor(ui, 'https://' + host + (m[2] || ''),
      download(address.replace('/report.json?', '/report-script.json?')));
    engine[action](ui, download(address));
  } catch (e) {
    ui.alert('Could not retrieve the report:\n\n' + e.message);
  }
}

function isTrustedHost(host) {
  return host === TRUSTED_DOMAIN
    || host.slice(-(TRUSTED_DOMAIN.length + 1)) === '.' + TRUSTED_DOMAIN;
}

function download(url) {
  var res  = UrlFetchApp.fetch(url, { muteHttpExceptions: true });
  var code = res.getResponseCode();

  if (code === 401) {
    throw new Error('Expired or invalid link (401): go back to Manager and click ' +
      '"Report data key" again (each link is valid for one hour).');
  }
  if (code === 404) { throw new Error('Extraction not found (404).'); }
  if (code !== 200) { throw new Error('Unexpected response: HTTP ' + code); }

  return JSON.parse(res.getContentText());
}

/**
 * The engine to run: the one stored for this Manager, or the published one
 * when none is stored yet, or when it is newer and the update is accepted.
 * A published engine is stored only once it has been verified.
 */
function engineFor(ui, origin, published) {
  if (typeof published.version !== 'number' || typeof published.code !== 'string') {
    throw new Error('Invalid rendering engine received from ' + origin + '.');
  }

  var stored = readEngine(origin);
  var adopt  = !stored;
  if (stored && published.version > stored.version) {
    adopt = ui.alert('Update available',
      'A new version of the report engine is published by ' + origin +
      ' (v' + stored.version + ' → v' + published.version + ').\n\nDo you want to update?',
      ui.ButtonSet.YES_NO) === ui.Button.YES;
  }

  if (!adopt) { return instantiate(origin, stored); }

  var engine = instantiate(origin, published);
  storeEngine(origin, published);
  return engine;
}

function instantiate(origin, source) {
  var engine = new Function(source.code)();
  if (!engine || engine.version !== source.version
    || typeof engine.fill !== 'function' || typeof engine.check !== 'function') {
    throw new Error('Engine v' + source.version + ' from ' + origin + ' is invalid; ' +
      'nothing was changed in this document.');
  }
  return engine;
}

function readEngine(origin) {
  var p       = PropertiesService.getDocumentProperties().getProperties();
  var key     = KEY_PREFIX + origin + '|';
  var version = Number(p[key + 'version']);
  var count   = Number(p[key + 'chunks']);
  if (!version || !count) { return null; }

  var code = '';
  for (var i = 0; i < count; i++) {
    if (typeof p[key + i] !== 'string') { return null; }
    code += p[key + i];
  }

  return { version: version, code: code };
}

function storeEngine(origin, engine) {
  var properties = PropertiesService.getDocumentProperties();
  var key        = KEY_PREFIX + origin + '|';

  properties.getKeys().forEach(function (k) {
    if (k.indexOf(key) === 0) { properties.deleteProperty(k); }
  });

  var values = {};
  var count  = 0;
  for (var start = 0; start < engine.code.length; count++) {
    var end = Math.min(start + CHUNK_SIZE, engine.code.length);
    // Never split the two halves of a non-BMP character (emoji).
    if (end < engine.code.length && /[\uD800-\uDBFF]/.test(engine.code.charAt(end - 1))) { end--; }
    values[key + count] = engine.code.substring(start, end);
    start = end;
  }
  values[key + 'version'] = String(engine.version);
  values[key + 'chunks']  = String(count);
  properties.setProperties(values);
}
