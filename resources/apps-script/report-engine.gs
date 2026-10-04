/**
 * SatelliteWP report — rendering engine, served by Manager
 * (GET …/report-script.json?token=…) and run by the loader pasted into the
 * Google Doc (resources/apps-script/loader.gs).
 *
 * This file is a function body: it ends by returning the module
 * { version, fill, check }. Bump VERSION on every change, otherwise documents
 * keep their stored copy.
 *
 * The engine decides no data: Manager sends every field already formatted,
 * with its type ('value', 'table', 'observations') and its colour.
 *
 * Variable syntax in the Doc:
 *   {{name}}                     a value, a table or observations
 *   {{name:red,orange}}          observations filtered by colour
 *   {{name:red,orange:short}}    short form: titles only, in 2 columns
 *   {{name::short}}              short form, every colour
 * Without a filter every colour is shown; the default form is "long".
 *
 * Observations (long form): a coloured ◼ + bold title, then the description;
 * a purple observation (client action) becomes a ☐ checkbox followed by the
 * title and description, to tick on paper.
 */

var VERSION = 29;

var COLORS = {
  red:    '#c0392f',
  orange: '#b96510',
  purple: '#7c5cd6',
  blue:   '#2f7fe0',
  green:  '#1f9d57',
  grey:   '#64748b'
};

var TEXT_COLOR = '#1a1a1a';

var RENDERERS = {
  value: insertValue,
  table: insertTable,
  observations: insertObservations
};

/** Each distinct {{token}}, suffixes included, in order of appearance. */
function tokensInText(text) {
  var pattern = /\{\{([a-zA-Z0-9_]+(?::[a-zA-Z0-9_]*(?:,[a-zA-Z0-9_]+)*)?(?::[a-zA-Z0-9_]+)?)\}\}/g;
  var found   = [];
  var m;
  while ((m = pattern.exec(text)) !== null) {
    if (found.indexOf(m[1]) === -1) { found.push(m[1]); }
  }
  return found;
}

/** "name:red,orange:short" → { name, colors: ["red","orange"], mode: "short" } — positional segments. */
function parseToken(token) {
  var parts = token.split(':');
  return {
    name:   parts[0],
    colors: parts.length >= 2 && parts[1] !== '' ? parts[1].split(',') : null,
    mode:   parts.length >= 3 ? parts[2] : 'long'
  };
}

/** {{name}} as a search pattern (findText takes a regular expression). */
function tokenPattern(name) {
  return '\\{\\{' + name + '\\}\\}';
}

/**
 * Replaces {{name}} with its value, keeping the style already applied to the
 * token in the Doc; field.color, when given, only changes the colour.
 */
function insertValue(body, name, field) {
  var found = body.findText(tokenPattern(name));
  if (!found) { return false; }

  var text       = found.getElement().asText();
  var start      = found.getStartOffset();
  var attributes = text.getAttributes(start);
  var value      = field.value === null || field.value === undefined ? '' : String(field.value);

  text.deleteText(start, found.getEndOffsetInclusive());
  if (value.length > 0) {
    text.insertText(start, value);
    text.setAttributes(start, start + value.length - 1, attributes);
    if (field.color && COLORS[field.color]) {
      text.setForegroundColor(start, start + value.length - 1, COLORS[field.color]);
    }
  }

  return true;
}

/**
 * Replaces the line of {{name}} (alone on its line) with a table. The table is
 * created empty then filled cell by cell, so icons can be inserted.
 */
function insertTable(body, name, field) {
  var paragraph = topLevelParagraph(body, name);
  if (!paragraph) { return false; }

  var columnCount = field.headers.length;
  var rows = [];
  for (var r = 0; r < 1 + field.rows.length; r++) { rows.push(new Array(columnCount).fill('')); }

  var table = body.insertTable(body.getChildIndex(paragraph), rows);
  body.removeChild(paragraph);
  table.setColumnWidth(0, firstColumnWidth(field));

  for (var c = 0; c < columnCount; c++) {
    var header = table.getCell(0, c);
    header.setBackgroundColor('#eaeef3');
    fillCell(header, field.headers[c], null);
    header.editAsText().setBold(true);
  }

  field.rows.forEach(function (row, rowIndex) {
    row.forEach(function (cell, columnIndex) {
      fillCell(table.getCell(rowIndex + 1, columnIndex), cell.text, cell.color);
    });
  });

  return true;
}

/**
 * First-column width from its longest text. Estimated at ~6 pt per character
 * (Apps Script cannot measure rendered text), clamped to 60–220 pt.
 */
function firstColumnWidth(field) {
  var longest = String(field.headers[0]).length;
  field.rows.forEach(function (row) {
    longest = Math.max(longest, String(row[0].text).length);
  });

  return Math.max(60, Math.min(220, longest * 6 + 12));
}

/** Each icon is downloaded once per run. */
var IMAGE_CACHE = {};

/** Fills a cell; each [img url="…"] becomes an inline icon. */
function fillCell(cell, text, color) {
  var paragraph    = cell.getChild(0).asParagraph();
  var imagePattern = /\[img url="([^"]+)"\]/g;
  var last = 0;
  var m;

  while ((m = imagePattern.exec(text)) !== null) {
    if (m.index > last) { paragraph.appendText(text.substring(last, m.index)); }
    paragraph.appendInlineImage(imageAt(m[1])).setWidth(14).setHeight(14);
    last = imagePattern.lastIndex;
  }
  if (last < text.length) { paragraph.appendText(text.substring(last)); }

  if (color && COLORS[color]) {
    cell.editAsText().setForegroundColor(COLORS[color]);
  }
}

function imageAt(url) {
  if (!IMAGE_CACHE[url]) {
    IMAGE_CACHE[url] = UrlFetchApp.fetch(url, { muteHttpExceptions: true }).getBlob();
  }
  return IMAGE_CACHE[url];
}

/** Replaces the line of {{name}} with its observations, in long or short form. */
function insertObservations(body, name, field) {
  var paragraph = topLevelParagraph(body, name);
  if (!paragraph || !field.items || field.items.length === 0) { return false; }

  if (field.mode === 'short') {
    insertObservationsShort(body, paragraph, field.items);
    return true;
  }

  var position = body.getChildIndex(paragraph);
  var offset   = 0;

  field.items.forEach(function (item, i) {
    if (item.color === 'purple') {
      offset += insertActionItem(body, position + offset, item, i === 0);
      return;
    }

    var title = body.insertParagraph(position + offset, '◼ ' + item.title);
    offset++;
    title.setSpacingBefore(i === 0 ? 0 : 14).setSpacingAfter(3);
    var titleText = title.editAsText();
    titleText.setBold(true).setFontSize(13).setForegroundColor(TEXT_COLOR);
    titleText.setForegroundColor(0, 0, COLORS[item.color] || COLORS.grey); // only the square carries the colour

    var parsed = parseFormattedText(item.message);
    var description = body.insertParagraph(position + offset, parsed.text);
    offset++;
    description.setSpacingBefore(0).setSpacingAfter(0);
    applyMarks(description.editAsText().setBold(false).setFontSize(11).setForegroundColor(TEXT_COLOR), parsed.marks, 0);
  });

  body.removeChild(paragraph);
  return true;
}

/**
 * Short form: the title only (success or failure title by status), 11 pt, no
 * bold or colour, in 2 columns filled top to bottom, left first. A borderless
 * table: DocumentApp has no real page columns.
 */
function insertObservationsShort(body, paragraph, items) {
  var half = Math.ceil(items.length / 2);
  var rows = [];
  for (var r = 0; r < half; r++) {
    rows.push([
      items[r].title,
      items[r + half] ? items[r + half].title : ''
    ]);
  }

  var table = body.insertTable(body.getChildIndex(paragraph), rows);
  body.removeChild(paragraph);
  table.setBorderWidth(0);

  for (var row = 0; row < table.getNumRows(); row++) {
    for (var c = 0; c < 2; c++) {
      var cell = table.getCell(row, c);
      cell.setPaddingTop(2).setPaddingBottom(2);
      cell.editAsText().setBold(false).setFontSize(11).setForegroundColor(TEXT_COLOR);
    }
  }
}

/**
 * Purple observation (client action): a single "☐ title — description" line,
 * not bold, to tick once printed. Returns the number of paragraphs inserted.
 */
function insertActionItem(body, position, item, first) {
  var raw = item.title && item.message ? item.title + ' — ' + item.message : (item.title || item.message || '');
  var parsed = parseFormattedText(raw);

  var p = body.insertParagraph(position, '☐ ' + parsed.text);
  p.setSpacingBefore(first ? 0 : 8).setSpacingAfter(4);
  // Offset of 2: the box and the space precede the parsed text.
  applyMarks(p.editAsText().setBold(false).setFontSize(11).setForegroundColor(TEXT_COLOR), parsed.marks, 2);

  return 1;
}

function applyMarks(text, marks, shift) {
  marks.forEach(function (mark) {
    var start = mark.start + shift;
    var end   = mark.end + shift;
    if (mark.bold) { text.setBold(start, end, true); }
    if (mark.italic) { text.setItalic(start, end, true); }
    if (mark.link) { text.setLinkUrl(start, end, mark.link); }
  });
}

/**
 * Strips the **bold**, _italic_ (word start/end only) and [text](https://url)
 * markers — the same syntax as Manager's format_observation_text() — and
 * returns the marks with their positions in the cleaned text.
 */
function parseFormattedText(text) {
  var markPattern = /\*\*(.+?)\*\*|(?<![\p{L}\p{N}])_(.+?)_(?![\p{L}\p{N}])|\[(.+?)\]\((https?:\/\/[^\s)]+)\)/gu;
  var clean = '';
  var marks = [];
  var last  = 0;
  var m;

  while ((m = markPattern.exec(text)) !== null) {
    clean += text.substring(last, m.index);
    var start = clean.length;

    if (m[1] !== undefined) {
      clean += m[1];
      marks.push({ start: start, end: clean.length - 1, bold: true });
    } else if (m[2] !== undefined) {
      clean += m[2];
      marks.push({ start: start, end: clean.length - 1, italic: true });
    } else {
      clean += m[3];
      marks.push({ start: start, end: clean.length - 1, link: m[4] });
    }

    last = markPattern.lastIndex;
  }
  clean += text.substring(last);

  return { text: clean, marks: marks };
}

/** The top-level paragraph containing {{name}}, or null. */
function topLevelParagraph(body, name) {
  var found = body.findText(tokenPattern(name));
  if (!found) { return null; }

  var element = found.getElement();
  while (element && element.getParent() && element.getParent().getType() !== DocumentApp.ElementType.BODY_SECTION) {
    element = element.getParent();
  }

  return element;
}

/** Fills each {{variable}} of the document that has data in the report. */
function fill(ui, data) {
  var body   = DocumentApp.getActiveDocument().getBody();
  var filled = [];

  // Driven by the document's {{tokens}}: one field can appear several times
  // with different filters.
  tokensInText(body.getText()).forEach(function (token) {
    var parsed = parseToken(token);
    var field  = data.fields[parsed.name];
    if (!field) { return; }

    if (field.type === 'observations') {
      var items = field.items;
      if (parsed.colors) {
        items = items.filter(function (item) { return parsed.colors.indexOf(item.color) !== -1; });
      }
      field = { type: field.type, items: items, mode: parsed.mode };
    }

    var render = RENDERERS[field.type];
    if (render && render(body, token, field)) { filled.push(token); }
  });

  ui.alert(filled.length
    ? 'Variables filled (' + filled.length + '):\n\n{{' + filled.join('}}\n{{') + '}}'
    : 'Report received, but none of its variables were found in this document.');
}

/** Lists the document's {{variables}} that have no data in the extraction. */
function check(ui, data) {
  var found    = tokensInText(DocumentApp.getActiveDocument().getBody().getText());
  var provided = Object.keys(data.fields || {});
  var missing  = found.filter(function (token) {
    return provided.indexOf(parseToken(token).name) === -1;
  });

  if (found.length === 0) {
    ui.alert('No {{variable}} found in this document.');
  } else if (missing.length === 0) {
    ui.alert('All ' + found.length + ' {{variables}} of the document are provided by this extraction.');
  } else {
    ui.alert(missing.length + ' of ' + found.length + ' {{variables}} of the document ' +
      'have no data in this extraction:\n\n{{' + missing.join('}}\n{{') + '}}');
  }
}

return { version: VERSION, fill: fill, check: check };
