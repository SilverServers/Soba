/* THE RICH-TEXT EDITOR — authored here, no dependency.
   ════════════════════════════════════════════════════════════════════════════

   Binds to every `[data-editor]` textarea. Nothing third-party is loaded at
   runtime: the attribute is the binding hook, and this file is what binds to
   it.

   ── THE TEXTAREA IS STILL THE THING THAT POSTS ──────────────────────────────
   This is the whole architecture of the file and everything else follows from
   it. The textarea is not replaced, it is HIDDEN, and a `contenteditable`
   surface is inserted beside it. Every keystroke writes the surface's HTML back
   into the textarea's value.

   Three properties fall out of that, none of which a "replace the textarea"
   design has:

     · WITH JAVASCRIPT OFF, or if this file 404s, or if it throws on line one,
       the page is exactly what it was before: a plain textarea that posts.
       That is the documented degraded state.
     · THE FORM DOES NOT CHANGE. No hidden input, no submit handler, no
       serialisation step to get wrong. Whatever posted before posts now.
     · THE SERVER IS UNAFFECTED. `Html\Sanitizer` cleans the value on the way
       into the database whether it came from this editor, a plain textarea, an
       import, the assistant or a hand-made POST.

   ── THE SANITISER IS THE GUARANTEE; THIS IS A CONVENIENCE ───────────────────
   Nothing here is a security control. The paste handler below strips markup
   because a paste from Word is unusable otherwise, not because stripping it in
   the browser protects anybody — a browser control protects nobody, and
   `Html\Sanitizer` runs on every save regardless.

   ── document.execCommand, WHICH IS DEPRECATED ───────────────────────────────
   Deliberate, and worth stating because it will look like an oversight.

   It is deprecated and it is implemented by every browser, has been for twenty
   years, and is not going anywhere while the whole web's editors depend on it.
   The alternative is hand-written Range and Selection manipulation: splitting
   text nodes at boundaries, merging adjacent identical formatting, handling a
   selection that starts in one paragraph and ends in another, and undo. That is
   thousands of lines of the most bug-prone code there is, and getting it subtly
   wrong means an owner loses a paragraph.

   `execCommand` also gives NATIVE UNDO — ctrl+Z works across formatting and
   typing together, which a hand-rolled implementation has to build from
   nothing and invariably gets wrong at the boundary.

   If it is ever actually removed, this file is the only thing that changes, and
   the textarea underneath still posts.

   No build step. Plain script tag, deferred. */

(function () {
  'use strict';

  // ── THE TOOLBAR ───────────────────────────────────────────────────────────
  //
  // Every one of these produces a tag `Html\Sanitizer::TAGS` allows. A button
  // that produced something the sanitiser strips would be a control that
  // silently does nothing the moment you press Save — and a control that is
  // drawn has to work, here as much as on a content type.
  //
  // `title` is the tooltip and the accessible name. No `data-tip`: that is
  // reserved for the admin's own tooltip component and these are native.
  // ── LETTERS WHERE LETTERS ARE THE CONVENTION, ICONS WHERE THEY EXIST ─────
  // The admin's icon set has no bold, italic or strikethrough glyph, and
  // drawing three by hand would put a different hand's silhouettes in a row
  // beside the originals at 14px. `B` in bold, `I` in italic and `S` struck
  // through are what every writing tool has used for thirty years, they are
  // more legible than any icon of them at this size, and they cannot render as
  // a box.
  //
  // The block types are neither: they are a SELECT, because eight of them is
  // not a row of buttons anybody can read. See `BLOCK_MENU`.
  //
  // The first version used Unicode for the rest — `⛓` link, `⛓̸` unlink,
  // `⌫` clear. Screenshotted, all three were tofu. `Ui\Editor` names the five
  // that have real SVGs and hands them over in a <template>; anything with no
  // `icon` key falls back to its `label` text, so a missing glyph degrades to a
  // word rather than to a box.
  // ── THE BLOCK MENU ────────────────────────────────────────────────────────
  //
  // A SELECT, because eight buttons for eight block types is a toolbar nobody
  // can read. It replaces a pair of H2 and H3 buttons, and it is strictly
  // more: H4, H5, H6 and Preformatted are allowed by `Html\Sanitizer::TAGS`
  // and need a way to be reached, and "back to Paragraph" would otherwise be
  // reachable only by pressing the heading button a second time — which
  // nobody guesses.
  //
  // NO H1. The entry's title is the page's `h1`; a second one is a document with
  // two titles. `TAGS` refuses it, so a menu entry for it would be a control
  // whose output the sanitiser deletes.
  var BLOCK_MENU = [
    ['p',          'Paragraph'],
    ['h2',         'Heading 2'],
    ['h3',         'Heading 3'],
    ['h4',         'Heading 4'],
    ['h5',         'Heading 5'],
    ['h6',         'Heading 6'],
    ['pre',        'Preformatted']
    // NO QUOTE. Blockquote keeps its own BUTTON — it is the one block in this
    // list a person reaches for while writing rather than while structuring,
    // and it already has a glyph. Listing it in
    // both places would be two controls for one thing, which is how a menu
    // teaches its user that the software is arbitrary.
  ];

  var TOOLS = [
    { block: true, title: 'Paragraph style' },
    { sep: true },
    { cmd: 'bold',   label: 'B', title: 'Bold',   key: 'b', style: 'font-weight:700' },
    { cmd: 'italic', label: 'I', title: 'Italic', key: 'i', style: 'font-style:italic' },
    // `s`, and NOT `del`. `<del>` means "this was removed from the document" and
    // carries a meaning a screen reader announces; `<s>` is "no longer accurate
    // or relevant", which is what a person pressing a strikethrough button on a
    // price or an old date actually means. Both are in `TAGS`; this is the one
    // that matches the gesture.
    { cmd: 'strikeThrough', label: 'S', title: 'Strikethrough',
      style: 'text-decoration:line-through' },
    { sep: true },
    { cmd: 'insertUnorderedList', icon: 'bullets', label: 'List',  title: 'Bulleted list' },
    { cmd: 'insertOrderedList',   icon: 'numbers', label: '1.',    title: 'Numbered list' },
    // ── INDENT MEANS NEST A LIST ITEM, AND ONLY THAT ───────────────────────
    // `execCommand('indent')` on a plain paragraph emits
    // `<blockquote>` in some browsers and `style="margin-left:40px"` in others.
    // The sanitiser strips the style and the blockquote is a different thing
    // entirely, so outside a list this is a button that appears to do something
    // and then loses it on save. It is DISABLED outside a list instead, which
    // is the honest state — see `refresh()`.
    { cmd: 'outdent', icon: 'outdent', label: '<', title: 'Move out of the list' },
    { cmd: 'indent',  icon: 'indent',  label: '>', title: 'Nest under the item above' },
    { sep: true },
    // ── TEXT ALIGNMENT ─────────────────────────────────────────────────────
    // `Html\Sanitizer::TEXT_CLASSES` is the vocabulary — `text-*`, deliberately
    // NOT the `align-*` an image uses, because those float and these do not.
    { cmd: 'textAlign', arg: 'text-left',   icon: 'text-left',   label: 'Left',
      title: 'Align left' },
    { cmd: 'textAlign', arg: 'text-center', icon: 'text-center', label: 'Centre',
      title: 'Align centre' },
    { cmd: 'textAlign', arg: 'text-right',  icon: 'text-right',  label: 'Right',
      title: 'Align right' },
    { sep: true },
    { cmd: 'formatBlock', arg: 'blockquote', icon: 'quote', label: 'Quote', title: 'Quote' },
    // ONE BUTTON, NOT TWO. There is no unlink icon in the set, so rather than
    // invent a fifth silhouette nobody recognises, this TOGGLES: pressed with
    // the caret inside a link it removes the link. Its title says which it will
    // do, and `refresh()` keeps that honest as the caret moves.
    { cmd: 'link', icon: 'link', label: 'Link', title: 'Link' },
    { cmd: 'formatInline', arg: 'code', icon: 'code', label: 'Code', title: 'Code' },
    // A RULE IS A BLOCK, not a decoration — `<hr>` is in `TAGS` and the theme
    // styles it. `insertHorizontalRule` is the one execCommand that inserts an
    // element rather than wrapping one, which is why the tidy-up below matters:
    // dropped inside a `<p>` it makes `<p><hr></p>`, and `unnestBlocks()` lifts
    // it back out the same way it does for a list.
    { cmd: 'insertHorizontalRule', icon: 'rule', label: '—', title: 'Horizontal line' },
    // ── A TABLE ────────────────────────────────────────────────────────────
    // `table`, `thead`, `tbody`, `tr`, `th`, `td` and `caption` are in
    // `Html\Sanitizer::TAGS`, so the editor has to be able to make one. A tag
    // the storage accepts and the editor cannot make is a capability the owner
    // does not have — the same shape as a content type that exists and cannot
    // be edited.
    { cmd: 'table', icon: 'table', label: 'Table', title: 'Insert a table' },
    // ── Ω, WHICH IS WHAT EVERY EDITOR USES FOR THIS ────────────────────────
    // A letter rather than an icon, for the same reason `B` and `I` are: the
    // set has no "special character" silhouette, omega is the conventional
    // face for this button, and it is in every font a browser falls back to,
    // so it never renders as a box.
    { cmd: 'char', label: '\u03a9', title: 'Insert a character' },
    { sep: true },
    // ── UNDO AND REDO GET BUTTONS, NOT JUST SHORTCUTS ──────────────────────
    // ⌘Z has always worked here — `execCommand` gives native undo, which is
    // half the reason this file uses it. The buttons are for the person who
    // does not know that, which on a CMS for non-technical owners is most of
    // them. Every word processor has both.
    { cmd: 'undo', icon: 'undo', label: 'Undo', title: 'Undo' },
    { cmd: 'redo', icon: 'redo', label: 'Redo', title: 'Redo' },

    // ── NO "CLEAR" BUTTON ────────────────────────────────────────────────
    // A "Clear" that runs `removeFormat` cannot be told from its label: it
    // reads as though it might empty the document, which is an alarming thing
    // to have sitting on a toolbar next to the writing. It would also be the
    // odd one out — a word among glyphs — and everything it does is already
    // reachable by pressing the button that applied the formatting.
    //
    // A control whose purpose cannot be guessed from its face is a control
    // that gets pressed once, warily, and then avoided.

      // ── NO SPACER, AND NO ROW BREAK ─────────────────────────────────────
    // A `flex: 1 1 auto` span pushing the view tabs to the far end FORCES A
    // WRAP with a full toolbar: a growing item is measured before the line
    // breaks, so the tabs are pushed onto a second row and then left sitting at
    // its left-hand edge.
    //
    // The tabs are not on this bar at all — they are in the head strip with
    // Add image — so there is nothing to push and the row fits.
  ];

  // ── WHAT A SELECTED IMAGE OFFERS ─────────────────────────────────────────
  //
  // Click a picture and it gets controls of its own, on a small bar floating
  // over it. That is the floor for an editor that holds pictures.
  //
  // ALIGNMENT IS THE POINT OF IT. "Text wrap" is the single most-used control in
  // any editor's image menu — the difference between a page that reads and a
  // stack of pictures with paragraphs between them. `Html\Sanitizer::CLASSES`
  // is the vocabulary and the public theme owns what the words mean.
  var IMAGE_TOOLS = [
    { img: 'align', arg: 'align-left',   icon: 'align-left',   label: 'Left',   title: 'Wrap text on the right' },
    { img: 'align', arg: 'align-center', icon: 'align-center', label: 'Centre', title: 'Centre, on its own line' },
    { img: 'align', arg: 'align-right',  icon: 'align-right',  label: 'Right',  title: 'Wrap text on the left' },
    // NO GLYPH. There is no "no alignment" silhouette in the icon set and
    // inventing one would be a shape nobody recognises beside three they do.
    { img: 'align', arg: 'align-none',   label: 'None',   title: 'No wrapping', text: true },
    { sep: true },
    { sep: true },
    // ── ONE DIALOG INSTEAD OF TWO PROMPTS ────────────────────────────────
    // ONE form holds everything about the picture, rather than a Caption
    // button and an Alt button each opening a one-field prompt: alt and
    // caption are usually written in the same breath, and there would be
    // nowhere to put width or a link without a third and fourth button on a
    // bar that floats over the image and cannot grow.
    //
    // Nothing is lost — both are in the dialog, one click away, next to two
    // things that had no control at all.
    { img: 'details', icon: 'details',   label: 'Edit',    title: 'Image details' },
    { img: 'replace', icon: 'replace',   label: 'Replace', title: 'Choose a different image' },
    { sep: true },
    { img: 'remove',  icon: 'remove',    label: 'Remove',  title: 'Remove this image' }
    // ── NO "DONE" ────────────────────────────────────────────────────────
    // There was one, because the bar REPLACED the writing toolbar and there had
    // to be a way back to it. Floating over the picture there is nothing to go
    // back to — the writing controls never left — and clicking anywhere else is
    // the exit, which is what every editor does and what nobody has to be told.
  ];

  // ── THE CHARACTERS A KEYBOARD DOES NOT HAVE ──────────────────────────────
  //
  // NAMED, NOT A GRID. A wall of glyphs expects you to recognise the one you
  // want, and half of them are indistinguishable at 14px — an en dash, an em
  // dash and a hyphen are three lines of different lengths. A named list says
  // which is which, and it is a `select`, so the keyboard and the screen reader
  // work without a line of code.
  //
  // THE LIST IS SHORT ON PURPOSE. These are the ones a site owner writing about
  // opening hours, prices and people actually reaches for. A longer list is a
  // longer scroll past the same six.
  var CHARACTERS = [
    ['—', 'Em dash —  (a break in a sentence)'],
    ['–', 'En dash –  (a range: 9–5)'],
    ['…', 'Ellipsis …'],
    ['·', 'Middle dot ·'],
    ['•', 'Bullet •'],
    ['×', 'Times ×  (4 × 6)'],
    ['½', 'Half ½'],
    ['¼', 'Quarter ¼'],
    ['¾', 'Three quarters ¾'],
    ['°', 'Degree °'],
    ['©', 'Copyright ©'],
    ['®', 'Registered ®'],
    ['™', 'Trademark ™'],
    ['€', 'Euro €'],
    ['£', 'Pound £'],
    ['¥', 'Yen ¥'],
    ['→', 'Right arrow →'],
    ['←', 'Left arrow ←'],
    ['“', 'Opening quote “'],
    ['”', 'Closing quote ”'],
    ['‘', 'Opening single quote ‘'],
    ['’', 'Apostrophe ’  (and closing single quote)'],
    ['«', 'Opening guillemet «'],
    ['»', 'Closing guillemet »'],
    ['\u00a0', 'Non-breaking space  (keeps two words together)']
  ];

  // ── WHAT A TABLE OFFERS WHILE THE CARET IS IN ONE ────────────────────────
  //
  // WORDS, NOT GLYPHS. The icon set has `plus`, `minus` and `table`, and every
  // combination of them for "add a row below" is a rebus: two silhouettes side
  // by side that the reader has to decode. "+ Row" cannot be misread, and
  // `.editor__btn--text` already exists for exactly this.
  //
  // WHICH ROW, AND WHICH COLUMN: the one the caret is in. Add puts the new one
  // AFTER it, which is what pressing Tab at the end of a table does everywhere
  // else and what a person means by "another row".
  var TABLE_TOOLS = [
    { tbl: 'row-add',  label: '+ Row', title: 'Add a row below this one', text: true },
    { tbl: 'row-del',  label: '− Row', title: 'Delete this row',          text: true },
    { sep: true },
    { tbl: 'col-add',  label: '+ Col', title: 'Add a column after this one', text: true },
    { tbl: 'col-del',  label: '− Col', title: 'Delete this column',          text: true },
    { sep: true },
    // THE WHOLE TABLE, and the only destructive control here that cannot be
    // reached by pressing the one beside it repeatedly.
    { tbl: 'remove',   icon: 'remove', label: 'Delete', title: 'Delete the table' }
  ];

  /** The <template> of SVGs `Ui\Editor::iconTemplate()` put on the page. */
  function iconNode(key) {
    var tpl = document.querySelector('template[data-editor-icons]');
    if (!tpl) { return null; }
    var found = tpl.content.querySelector('[data-icon="' + key + '"] svg');
    return found ? found.cloneNode(true) : null;
  }

  // ── KEEPING THE CARET WHERE THE AUTHOR LEFT IT ────────────────────────────
  //
  // MEASURED, not assumed: with the caret at offset 10 of "First item",
  // `execCommand('insertUnorderedList')` produces the right markup and leaves
  // the caret at offset 0. The next character typed goes to the FRONT of the
  // line. `surface.focus()` was the obvious suspect and was tested and cleared
  // — it leaves the offset at 10; the command itself restructures the DOM into
  // `<ul><li>…</li></ul>`, destroying the text node the selection pointed into.
  //
  // The fix is the standard one and it is deliberately the SIMPLEST version:
  // count how many characters precede the caret in the whole surface, run the
  // command, then walk the text nodes again and put the caret back at that
  // count. It works because none of these commands CHANGES the text — they
  // only change what wraps it — so the character offset is stable across them.
  //
  // It does not survive a command that edits text, and there is not one here.
  // If one is ever added, this is what will need to be cleverer.

  /** How many characters precede the caret, counting the whole surface. */
  function caretOffset(surface) {
    var sel = window.getSelection();
    if (!sel || !sel.rangeCount) { return null; }
    var range = sel.getRangeAt(0).cloneRange();
    range.selectNodeContents(surface);
    range.setEnd(sel.getRangeAt(0).endContainer, sel.getRangeAt(0).endOffset);
    // AN EMBED IS NOT COUNTED. A quote's words are text in the surface, and a
    // caret counted into them lands inside the block, where nothing can be typed.
    var before = range.cloneContents();
    Array.prototype.forEach.call(before.querySelectorAll('.editor__embed'), function (el) {
      el.parentNode.removeChild(el);
    });
    return before.textContent.length;
  }

  /** Put the caret that many characters into the surface. */
  function setCaretOffset(surface, offset) {
    if (offset === null) { return; }
    var walker = document.createTreeWalker(surface, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        return n.parentNode.closest('.editor__embed') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
      }
    });
    var seen = 0, node;
    while ((node = walker.nextNode())) {
      var len = node.textContent.length;
      if (seen + len >= offset) {
        var r = document.createRange();
        r.setStart(node, Math.max(0, offset - seen));
        r.collapse(true);
        var s = window.getSelection();
        s.removeAllRanges();
        s.addRange(r);
        return;
      }
      seen += len;
    }
    // Past the end — which happens when the command removed a trailing space.
    // The end of the surface is the least surprising place to be.
    var last = document.createRange();
    last.selectNodeContents(surface);
    last.collapse(false);
    var sel2 = window.getSelection();
    sel2.removeAllRanges();
    sel2.addRange(last);
  }

  /**
   * Make sure the surface's top level is blocks, not loose text.
   *
   * Runs on load and after every Enter. Loose text nodes and inline elements at
   * the top level are gathered into a `<p>`; an entirely empty surface gets an
   * empty one so there is something to type into.
   */
  function ensureBlock(surface) {
    var BLOCKS = /^(P|H2|H3|H4|H5|H6|UL|OL|BLOCKQUOTE|PRE|TABLE|FIGURE|DIV|HR|DL|IFRAME)$/;

    if (!surface.firstChild) {
      // `<p><br></p>`, NOT `<p></p>`. An empty paragraph with no break has no
      // height and no place for a caret, so Chromium puts typed text BESIDE it
      // rather than inside it — the surface ended up as a bare text node
      // followed by an empty paragraph, which is the shape this function
      // exists to prevent. The `<br>` is the placeholder every browser uses for
      // an empty line and it is what makes the paragraph a place to type.
      var p = document.createElement('p');
      p.appendChild(document.createElement('br'));
      surface.appendChild(p);
      return;
    }

    var loose = [];
    Array.prototype.forEach.call(surface.childNodes, function (n) {
      var isBlock = n.nodeType === 1 && BLOCKS.test(n.nodeName);
      if (!isBlock) { loose.push(n); }
    });
    if (!loose.length) { return; }

    // Wrap each RUN of adjacent loose nodes, so text between two blocks becomes
    // its own paragraph rather than being hoisted to the front.
    //
    // A RUN THAT IS ONLY WHITE SPACE GOES. It is the line breaks of HTML typed
    // or pasted in the HTML view — `</p>\n\n<p>` — not a paragraph, and wrapped
    // it became an empty `<p>` that was saved and printed as a gap.
    var run = [];
    var flush = function () {
      if (!run.length) { return; }
      if (run.every(function (n) { return n.nodeType === 3 && /^[ \t\n\r\f]*$/.test(n.nodeValue); })) {
        run.forEach(function (n) { n.parentNode.removeChild(n); });
        run = [];
        return;
      }
      var p = document.createElement('p');
      run[0].parentNode.insertBefore(p, run[0]);
      run.forEach(function (n) { p.appendChild(n); });
      run = [];
    };
    Array.prototype.slice.call(surface.childNodes).forEach(function (n) {
      if (n.nodeType === 1 && BLOCKS.test(n.nodeName)) { flush(); return; }
      run.push(n);
    });
    flush();
    // A body that was only white space still needs a line to type on.
    if (!surface.firstChild) { ensureBlock(surface); }
  }

  /**
   * Hoist a block out of a paragraph — the live twin of the sanitiser's rule.
   *
   * `execCommand('insertUnorderedList')` inside a `<p>` produces
   * `<p><ul>…</ul></p>`, which is invalid. `Html\Sanitizer` repairs it on save,
   * so nothing WRONG is ever stored — but until the page is reloaded the
   * editor's own HTML disagrees with what the save will produce, and "what you
   * see is what you get" is the entire promise of an editor like this.
   *
   * Only the one nesting the commands here can actually create. A general
   * content-model repair is the HTML spec, and half of it would be worse than
   * the one case that happens.
   */
  function unnestBlocks(surface) {
    var BLOCKS = /^(P|H2|H3|H4|H5|H6|UL|OL|BLOCKQUOTE|PRE|TABLE|FIGURE|DIV|HR|DL)$/;

    var find = function () {
      var hosts = surface.querySelectorAll('p, h2, h3, h4, h5, h6');
      for (var i = 0; i < hosts.length; i++) {
        for (var j = 0; j < hosts[i].children.length; j++) {
          if (BLOCKS.test(hosts[i].children[j].nodeName)) {
            return { host: hosts[i], child: hosts[i].children[j] };
          }
        }
      }
      return null;
    };

    var guard = 0, hit;
    while ((hit = find()) && guard++ < 200) {
      var host = hit.host, child = hit.child;

      // ── THE PARAGRAPH SPLITS; IT DOES NOT GET JUMPED OVER ────────────────
      // This used to insert the block BEFORE the host, which moved a captioned
      // picture to the top of the paragraph it was sitting in the middle of:
      // "Before. [image] After." became the figure, then "Before. After.". The
      // author's picture silently changed position, which is the worst thing an
      // editor can do quietly.
      //
      // Splitting keeps the reading order — what was before the picture stays
      // before it, what was after becomes a new paragraph after it — which is
      // what every editor does and what the author meant.
      var tail = null;
      if (child.nextSibling) {
        tail = document.createElement(host.nodeName.toLowerCase());
        while (child.nextSibling) { tail.appendChild(child.nextSibling); }
      }

      host.parentNode.insertBefore(child, host.nextSibling);
      if (tail && (tail.textContent.trim() || tail.querySelector('img, br'))) {
        child.parentNode.insertBefore(tail, child.nextSibling);
      }

      // An element left with nothing in it was only ever the wrapper.
      if (!host.textContent.trim() && !host.querySelector('img, br')) {
        host.parentNode.removeChild(host);
      }
    }
  }

  /**
   * A sub-list belongs INSIDE the item it is nested under, not beside it.
   *
   * `execCommand('indent')` on a list item produces
   * `<ul><li>one</li><ul><li>two</li></ul></ul>` — the sub-list is a SIBLING of
   * the item, and a `ul` may contain nothing but `li`. Browsers render it as
   * though it were nested, which is exactly why it survived a screenshot; the
   * markup in the HTML view was wrong and a screen reader announces a phantom
   * empty item.
   *
   * The live twin of `Html\Sanitizer::reparentStrayList()`. The sanitiser is
   * the guarantee — it catches the same shape arriving from the HTML view, an
   * import or a paste — but until the page is reloaded the editor's HTML would
   * disagree with what the save produces, and "what you see is what you get" is
   * the whole promise.
   */
  /**
   * `execCommand('strikeThrough')` emits `<strike>`, which is not in `TAGS`.
   *
   * ── FORMATTING THAT LASTS ONLY UNTIL SAVE ──────────────────────────────────
   * Left alone, the button works, the text is struck through on screen, and the
   * sanitiser deletes the tag on save — so the formatting survives until the
   * author stops looking. Only the MARKUP the textarea posts shows it; the
   * surface looks right.
   *
   * `<strike>` is the deprecated spelling of `<s>` and means the same thing, so
   * this is a rename and not a repair. Chromium also uses it for the UN-strike
   * pass, which is why `execCommand` is still the thing doing the work — it
   * handles toggling a partial selection, which by-hand DOM surgery does not.
   */
  function fixStrike(surface) {
    Array.prototype.slice.call(surface.querySelectorAll('strike')).forEach(function (old) {
      var s = document.createElement('s');
      while (old.firstChild) { s.appendChild(old.firstChild); }
      old.parentNode.replaceChild(s, old);
    });
  }

  function fixLists(surface) {
    Array.prototype.slice.call(surface.querySelectorAll('ul > ul, ul > ol, ol > ul, ol > ol'))
      .forEach(function (stray) {
        var prev = stray.previousElementSibling;
        while (prev && prev.nodeName !== 'LI') { prev = prev.previousElementSibling; }
        if (!prev) {
          prev = document.createElement('li');
          stray.parentNode.insertBefore(prev, stray);
        }
        prev.appendChild(stray);
      });

    // ── AND THE OTHER HALF: AN ITEM INSIDE AN ITEM ─────────────────────────
    // `execCommand('outdent')` on a nested item unwraps the sub-list but leaves
    // the item where it was, giving `<li>One<li>Two</li></li>` — which is not a
    // list of two, it is one item containing another. It RENDERS as two items,
    // so only the markup shows it — and the word count reads "OneTwo" as a
    // single word.
    //
    // The inner item becomes the next SIBLING of its host, which is what
    // outdent was asked to do.
    var guard = 0;
    var nested;
    while ((nested = surface.querySelector('li > li')) && guard++ < 100) {
      var host = nested.parentNode;
      host.parentNode.insertBefore(nested, host.nextSibling);
    }
  }

  /**
   * The surface's HTML — or nothing at all, when the surface is empty.
   *
   * `ensureBlock()` puts a `<p><br></p>` in an empty surface so there is
   * somewhere to type. That is SCAFFOLDING, not content: shown in the HTML view
   * it means a brand-new page opens with markup nobody wrote, and STORED it
   * means an "empty" page holds a paragraph — so `Page::find()` reports a body
   * where there is none, and every "does this page have content?" test in the
   * system is wrong by one empty paragraph.
   *
   * MODULE SCOPE, not inside `build()`: `run()`, which is out here, calls it,
   * and from inside `build()` that would be a ReferenceError on every image
   * insert. The surface is passed in rather than closed over, which is what
   * makes the scope obvious instead of accidental.
   */
  function htmlOut(surface) {
    if (!surface.textContent.trim() && !surface.querySelector('img, hr, iframe')) {
      return '';
    }

    // ── EDITING STATE IS NOT CONTENT ────────────────────────────────────────
    // `is-selected` marks the picture the author has clicked. It is a fact
    // about this moment in the editor, not about the document — and it was
    // going straight into the textarea, so `class="align-right is-selected"`
    // appeared in the HTML view and would have been posted.
    //
    // `Html\Sanitizer` strips it on save (it is not in `CLASSES`), so nothing
    // wrong could ever be STORED — but the source view showed markup the author
    // did not write, and "what you see is what you get" is the whole promise.
    // Cleaned on a CLONE, so the live surface keeps its outline.
    var copy = surface.cloneNode(true);
    Array.prototype.forEach.call(copy.querySelectorAll('.is-selected'), function (el) {
      el.classList.remove('is-selected');
      if (el.getAttribute('class') === '') { el.removeAttribute('class'); }
    });

    // ── AND THE STRUCTURAL REPAIRS, HERE AND NOWHERE ELSE ──────────────────
    // `execCommand` produces markup that renders correctly and is wrong: a
    // sub-list as a SIBLING of its item, an item inside an item, `<strike>`
    // instead of `<s>`, a block left inside the paragraph it was inserted into.
    //
    // Repairing the live surface is what the undo stack cannot survive — see
    // the long note in `run()`. Repairing the CLONE gives the same answer to
    // everything that can see it (the textarea, the HTML view, the save) and
    // leaves the browser's own history alone.
    unnestBlocks(copy);
    fixLists(copy);
    fixStrike(copy);

    // ── AN EMBED IS ITS CODE ────────────────────────────────────────────────
    // The block on the surface is the editor's way of showing it; what goes
    // to the HTML view and the save is the code it keeps (`embedBlock()`),
    // after the repairs, so none of them ever reaches into it.
    Array.prototype.forEach.call(copy.querySelectorAll('.editor__embed[data-code]'), function (block) {
      var code = document.createElement('template');
      code.innerHTML = block.getAttribute('data-code');
      block.parentNode.replaceChild(code.content, block);
    });

    return copy.innerHTML;
  }

  /**
   * Remember the SELECTION, not just the caret.
   *
   * `caretOffset()` is enough for a collapsed caret, and a link is usually made
   * around SELECTED WORDS — a character offset cannot describe those. The Range
   * is cloned because the live one collapses the moment the dialog takes focus.
   *
   * Returns null for a collapsed caret, where the offset is the better answer
   * and `restoreRange()` falls back to it.
   */
  function saveRange() {
    var sel = window.getSelection();
    if (!sel || !sel.rangeCount || sel.isCollapsed) { return null; }
    return sel.getRangeAt(0).cloneRange();
  }

  function restoreRange(range, surface, fallbackOffset) {
    var sel = window.getSelection();
    if (range && surface.contains(range.commonAncestorContainer)) {
      sel.removeAllRanges();
      sel.addRange(range);
      return;
    }
    setCaretOffset(surface, fallbackOffset);
  }

  /** Put a node at the caret, or at the end if there is no caret. */
  function insertNode(surface, node) {
    var sel = window.getSelection();
    if (sel && sel.rangeCount && surface.contains(sel.anchorNode)) {
      var range = sel.getRangeAt(0);
      range.deleteContents();
      range.insertNode(node);
      // Caret AFTER the inserted node, so typing continues past it rather than
      // in front of it.
      range.setStartAfter(node);
      range.collapse(true);
      sel.removeAllRanges();
      sel.addRange(range);
    } else {
      surface.appendChild(node);
    }
    unnestBlocks(surface);
    ensureBlock(surface);
  }

  /** Is the caret inside a link right now? */
  function inLink() {
    var sel = window.getSelection();
    if (!sel || !sel.anchorNode) { return null; }
    var n = sel.anchorNode;
    while (n && n !== document.body) {
      if (n.nodeName === 'A') { return n; }
      n = n.parentNode;
    }
    return null;
  }

  // Blocks `formatBlock` toggles back OUT of, so pressing H2 on a heading
  // returns it to a paragraph rather than doing nothing.
  function currentBlock() {
    var b = document.queryCommandValue('formatBlock');
    return (b || '').toLowerCase().replace(/[<>]/g, '');
  }

  // ── WHAT TEXT ALIGNMENT CAN BE PUT ON ──────────────────────────────────────
  //
  // Exactly the tags `Html\Sanitizer::TAGS` allows a `class` on outside the
  // image pair. Kept as one list in one place: a button offering alignment on
  // something the sanitiser then strips is a control that works until you save,
  // which is the worst kind — the author has stopped looking by then.
  var ALIGNABLE = 'p,h2,h3,h4,h5,h6,blockquote,li';
  var TEXT_ALIGNS = ['text-left', 'text-center', 'text-right'];

  /**
   * Every alignable block the current selection touches.
   *
   * A collapsed caret gives one. A selection spanning three paragraphs gives
   * three — selecting a passage and pressing Centre has to centre the passage,
   * not the paragraph the caret happens to have landed in.
   */
  function alignedBlocks(surface) {
    var sel = window.getSelection();
    if (!sel || !sel.rangeCount) { return []; }
    var range = sel.getRangeAt(0);

    var all = Array.prototype.slice.call(surface.querySelectorAll(ALIGNABLE))
      .filter(function (el) {
        // A `li` inside a `li`, or a `p` inside a `blockquote`: take the
        // INNERMOST, or the class lands on the wrapper and centres more than
        // was selected.
        return !el.querySelector(ALIGNABLE);
      })
      .filter(function (el) { return range.intersectsNode(el); });

    if (all.length) { return all; }

    // No intersection — a collapsed caret in a browser whose `intersectsNode`
    // is unhappy with a zero-width range. Walk up from the anchor instead.
    var n = sel.anchorNode;
    while (n && n !== surface) {
      if (n.nodeType === 1 && n.matches && n.matches(ALIGNABLE)) { return [n]; }
      n = n.parentNode;
    }
    return [];
  }

  function build(textarea) {
    // ── ALREADY BOUND? ─────────────────────────────────────────────────────
    // The form builder redraws its canvas, and a screen that re-runs this would
    // otherwise stack a second toolbar and a second surface on the same
    // textarea — two editors writing to one value, last one wins.
    if (textarea.dataset.editorBound === '1') { return; }
    textarea.dataset.editorBound = '1';

    var wrap = document.createElement('div');
    wrap.className = 'editor';

    var bar = document.createElement('div');
    bar.className = 'editor__bar';
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Formatting');

    var surface = document.createElement('div');
    surface.className = 'editor__surface';
    surface.contentEditable = 'true';
    surface.spellcheck = true;
    // THE SURFACE IS THE LABELLED CONTROL NOW. A `<label for>` pointing at the
    // hidden textarea names something no pointer can reach, so a screen reader
    // announces the field and the caret lands nowhere.
    surface.setAttribute('role', 'textbox');
    surface.setAttribute('aria-multiline', 'true');
    var label = textarea.id && document.querySelector('label[for="' + textarea.id + '"]');
    if (label) {
      if (!label.id) { label.id = textarea.id + '-label'; }
      surface.setAttribute('aria-labelledby', label.id);
    } else if (textarea.getAttribute('aria-label')) {
      surface.setAttribute('aria-label', textarea.getAttribute('aria-label'));
    }
    if (textarea.placeholder) {
      surface.dataset.placeholder = textarea.placeholder;
    }

    // The textarea's own value is the starting content. It is HTML from the
    // database, already sanitised on the way in — or, after a save the server
    // refused, what was typed, which is why it goes through `surfaceHtml()`.
    surface.innerHTML = surfaceHtml(textarea.value);

    // ── THERE IS ALWAYS AT LEAST ONE BLOCK ────────────────────────────────
    // An empty contenteditable has no element in it at all, so the first thing
    // typed becomes a BARE TEXT NODE with no `<p>` around it — and from there
    // Enter and `formatBlock` both misbehave: pressing Enter did not start a
    // new block, so the next paragraph typed itself onto the end of the
    // previous one, and a heading applied to the lot.
    //
    // Found by composing a whole document in it and reading the HTML, not by
    // reading the code. `defaultParagraphSeparator` does not help — it says
    // what to create, not that there is something to split.
    //
    // Content loaded from the database can have the same shape: old rows were
    // written by a plain textarea, so `Everything your members need.` with no
    // tags at all is a perfectly ordinary stored value.
    ensureBlock(surface);

    // ── PUT THE BARS BACK IN STEP WITH THE CARET ───────────────────────────
    // `setSelectedTable` and `setSelectedImage` are driven by `keyup` and
    // `mouseup`, and a caret moved by CODE fires neither — so after any command
    // that ends by placing the caret somewhere (inserting a table, inserting an
    // image) the bars have to be told. Declared here and passed into `run()`
    // rather than reached for from module scope, which it cannot see.
    var afterCommand = function () {
        setSelectedTable(currentTable());
        refresh();
    };

    var buttons = [];
    var blockSelect = null;
    TOOLS.forEach(function (tool) {
      if (tool.sep) {
        var hr = document.createElement('span');
        hr.className = 'editor__sep';
        hr.setAttribute('aria-hidden', 'true');
        bar.appendChild(hr);
        return;
      }
      if (tool.block) {
        // ── THE BLOCK MENU ───────────────────────────────────────────────
        // A real `<select>`, so the keyboard, the screen reader and the
        // platform's own menu all work without a line of code. A div-with-a-
        // listbox-role would be five hundred lines to be worse at all three.
        //
        // `change`, NOT `mousedown`: a select is operated by choosing, and the
        // selection in the surface survives because the command runs from the
        // change handler, which restores the caret first.
        blockSelect = document.createElement('select');
        blockSelect.className = 'select select--sm editor__block';
        blockSelect.setAttribute('aria-label', tool.title);
        blockSelect.dataset.block = '';
        BLOCK_MENU.forEach(function (pair) {
          var o = document.createElement('option');
          o.value = pair[0];
          o.textContent = pair[1];
          blockSelect.appendChild(o);
        });
        blockSelect.addEventListener('change', function () {
          var at = caretOffset(surface);
          surface.focus();
          setCaretOffset(surface, at);
          run({ cmd: 'formatBlock', arg: blockSelect.value, force: true },
              surface, textarea, afterCommand);
          refresh();
        });
        bar.appendChild(blockSelect);
        return;
      }

      var b = document.createElement('button');
      b.type = 'button';                      // NEVER a submit. Inside a form,
                                              // a bare <button> submits it.
      b.className = 'editor__btn' + (tool.text ? ' editor__btn--text' : '');

      // ── `data-tip`, NOT `title` ────────────────────────────────────────
      // `tooltip.js`'s own header: "If an element has its own visible TEXT
      // LABEL, it does not get a tooltip. Not data-tip, not title=''. Allowed
      // only on … glyph-only icon buttons — and on any of those, pair data-tip
      // with aria-label."
      //
      // `title=` is the one the rule names explicitly as wrong: the native
      // tooltip cannot be styled or themed, and takes a second to appear.
      //
      // THE ACCESSIBLE NAME IS ALWAYS THE WORD, never the glyph. An icon button
      // with no `aria-label` announces as "button" and nothing else.
      b.setAttribute('aria-label', tool.title);

      var svg = tool.icon ? iconNode(tool.icon) : null;
      if (svg) {
        b.appendChild(svg);
        // ── A TIP ONLY WHERE THERE IS NO WORD ──────────────────────────────
        // The rule is `tooltip.js`'s, on every admin route: an element with
        // its own visible TEXT gets no tooltip, because a tooltip that
        // paraphrases a label is noise. That covers the lettered buttons too —
        // `B`, `I`, `None` — however much a letter looks like a glyph that
        // happens to be a letter.
        //
        // So the glyph buttons say what they are, and the lettered ones are
        // already saying it.
        b.setAttribute('data-tip', tool.title + (tool.key ? '  ⌘' + tool.key.toUpperCase() : ''));
      } else {
        b.textContent = tool.label;
      }
      if (tool.style) { b.style.cssText = tool.style; }

      // mousedown, NOT click: clicking moves focus out of the surface and the
      // selection collapses, so the command would apply to nothing. Preventing
      // the default on mousedown keeps the selection where the author put it.
      b.addEventListener('mousedown', function (e) {
        e.preventDefault();
        run(tool, surface, textarea, afterCommand);
        refresh();
      });
      // ── DURABLE HOOKS ────────────────────────────────────────────────
      // The accessible name moves — the link button becomes "Remove link"
      // inside a link — so nothing may select these by their word. `data-cmd`
      // and `data-arg` never change, and the three alignment buttons share a
      // command, so it takes both to name one of them.
      b.dataset.cmd = tool.cmd;
      if (tool.arg) { b.dataset.arg = tool.arg; }
      buttons.push({ el: b, tool: tool });
      bar.appendChild(b);
    });

    // ── THE HEAD STRIP: ADD IMAGE, AND WHICH VIEW YOU ARE IN ────────────────
    //
    // "Add image" is a BUTTON ABOVE the formatting controls, with the view
    // tabs at the top right of the same strip: inserting a picture is not
    // formatting the words, and choosing which view to work in is not either.
    //
    // It also settles the wrap. Twenty controls plus the tabs needed 764px in a
    // 732px column — measured with `nowrap` and `width: max-content` — so the
    // bar broke onto two lines. Moving these two out takes 137px off it and the
    // formatting row fits on one.
    var head = document.createElement('div');
    head.className = 'editor__head';

    // NOT A `.editor__btn`. This is a labelled action, not a glyph in a run of
    // them — it says "Add image" in words, and a word means no tooltip.
    var addImage = document.createElement('button');
    addImage.type = 'button';
    addImage.className = 'btn btn--sm editor__add';
    addImage.dataset.cmd = 'image';
    var addIcon = iconNode('image');
    if (addIcon) { addImage.appendChild(addIcon); }
    addImage.appendChild(document.createTextNode('Add image'));
    addImage.addEventListener('mousedown', function (e) {
      e.preventDefault();
      run({ cmd: 'image' }, surface, textarea, afterCommand);
    });
    head.appendChild(addImage);

    // IMPORT MARKDOWN, BESIDE IT, and for the same reason: bringing a document
    // in is not formatting the words. It works in either view.
    var addMarkdown = document.createElement('button');
    addMarkdown.type = 'button';
    addMarkdown.className = 'btn btn--sm editor__add';
    addMarkdown.dataset.cmd = 'markdown';
    var markdownIcon = iconNode('markdown');
    if (markdownIcon) { addMarkdown.appendChild(markdownIcon); }
    addMarkdown.appendChild(document.createTextNode('Import Markdown'));
    addMarkdown.addEventListener('mousedown', function (e) {
      e.preventDefault();
      importMarkdown();
    });
    head.appendChild(addMarkdown);

    var tabs = document.createElement('div');
    tabs.className = 'tabs tabs--inline editor__views';
    tabs.setAttribute('role', 'tablist');
    tabs.setAttribute('aria-label', 'View');

    var viewBtns = {};
    [['rich', 'Visual'], ['source', 'HTML']].forEach(function (pair) {
      var t = document.createElement('button');
      t.type = 'button';
      t.className = 'tabs__tab';
      t.setAttribute('role', 'tab');
      t.textContent = pair[1];
      t.addEventListener('mousedown', function (e) {
        e.preventDefault();
        setSourceMode(pair[0] === 'source');
      });
      viewBtns[pair[0]] = t;
      tabs.appendChild(t);
    });
    head.appendChild(tabs);

    // ── THE SHORTCUTS, AND ONLY THE ONES THAT EXIST ────────────────────────
    // This lists four, because four is how many there are — a help screen that
    // names a shortcut
    // the code does not implement is worse than no help screen.
    //
    // `?` and not a glyph: the icon set has no keyboard, and a question mark is
    // what "help" has looked like for thirty years.
    var keys = document.createElement('button');
    keys.type = 'button';
    keys.className = 'editor__btn editor__btn--text';
    keys.dataset.cmd = 'shortcuts';
    keys.textContent = '?';
    keys.setAttribute('aria-label', 'Keyboard shortcuts');
    keys.addEventListener('mousedown', function (e) {
      e.preventDefault();
      // The platform's own modifier, not a hard-coded one: telling a Windows
      // owner to press Cmd+B is telling them about somebody else's computer.
      var mod = /Mac|iPhone|iPad/.test(navigator.platform) ? '\u2318' : 'Ctrl+';
      window.Prompt.ask({
        title: 'Keyboard shortcuts',
        body: mod + 'B  bold\n'
            + mod + 'I  italic\n'
            + mod + 'U  underline\n'
            + mod + 'Z  undo, and Shift with it to redo\n\n'
            + 'Everything else is on the toolbar. Undo works across typing and '
            + 'formatting together, which is why this editor uses the browser\'s '
            + 'own editing commands.',
        verb: 'Close',
        fields: []
      });
    });
    head.appendChild(keys);

    // ── ROOM TO WRITE IN ───────────────────────────────────────────────────
    // A distraction-free toggle, at the far right of the same strip. A CLASS
    // on the wrapper and nothing else — the editor is
    // already a flex column, so fixing it to the viewport makes the surface
    // take the height without a line of layout code.
    var full = document.createElement('button');
    full.type = 'button';
    full.className = 'editor__btn editor__full';
    full.dataset.cmd = 'fullscreen';
    full.setAttribute('aria-label', 'Fill the screen');
    full.setAttribute('data-tip', 'Fill the screen');
    full.setAttribute('aria-pressed', 'false');
    var fullIcon = iconNode('fullscreen');
    if (fullIcon) { full.appendChild(fullIcon); }
    full.addEventListener('mousedown', function (e) {
      e.preventDefault();
      var on = !wrap.classList.contains('is-full');
      wrap.classList.toggle('is-full', on);
      document.body.classList.toggle('has-full-editor', on);
      full.setAttribute('aria-pressed', on ? 'true' : 'false');
      var word = on ? 'Back to the page' : 'Fill the screen';
      full.setAttribute('aria-label', word);
      full.setAttribute('data-tip', word);
      (sourceMode ? textarea : surface).focus();
    });
    head.appendChild(full);

    // ESCAPE IS THE WAY OUT OF ANYTHING IN THIS ADMIN, and a full-screen editor
    // with no visible page around it is the one place that matters most.
    wrap.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && wrap.classList.contains('is-full')) {
        e.preventDefault();
        full.dispatchEvent(new MouseEvent('mousedown'));
      }
    });

    // ── THE SECOND BAR: WHAT A SELECTED IMAGE OFFERS ───────────────────────
    // Built once and hidden, rather than created on each selection: a bar that
    // is rebuilt every click cannot keep a pressed state, and rebuilding is how
    // a listener gets bound twice.
    var imgBar = document.createElement('div');
    imgBar.className = 'editor__bar editor__bar--float editor__bar--image';
    imgBar.setAttribute('role', 'toolbar');
    imgBar.setAttribute('aria-label', 'Image');
    imgBar.hidden = true;

    var imgButtons = [];
    IMAGE_TOOLS.forEach(function (tool) {
      if (tool.sep) {
        var hr = document.createElement('span');
        hr.className = 'editor__sep';
        hr.setAttribute('aria-hidden', 'true');
        imgBar.appendChild(hr);
        return;
      }

      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'editor__btn' + (tool.text ? ' editor__btn--text' : '');
      b.setAttribute('aria-label', tool.title);
      var svg = tool.icon ? iconNode(tool.icon) : null;
      if (svg) {
        b.appendChild(svg);
        b.setAttribute('data-tip', tool.title);   // glyph-only — see above
      } else {
        b.textContent = tool.label;
      }
      b.addEventListener('mousedown', function (e) {
        e.preventDefault();
        runImage(tool, surface, textarea, setSelectedImage);
      });
      // ── THE DURABLE HOOK IS THE DATA ATTRIBUTE, NOT THE WORD ───────────
      // `refresh()` below REWRITES `aria-label` on this bar — the caption
      // button says "Add a caption" or "Edit the caption" depending on what is
      // selected, which is the right thing for a screen reader and the wrong
      // thing to select a button by: anything that does breaks the first time
      // the word changes under it. `data-img` never changes.
      b.dataset.img = tool.img;
      if (tool.arg) { b.dataset.arg = tool.arg; }
      imgButtons.push({ el: b, tool: tool });
      imgBar.appendChild(b);
    });
    wrap.appendChild(imgBar);

    // ── THE THIRD BAR: WHAT A TABLE OFFERS ─────────────────────────────────
    // Built and positioned exactly like the image one, because it is the same
    // idea: a control that belongs to a thing in the document rather than to
    // the document. Shown when the caret is inside a table, hidden otherwise,
    // and never at the same time as the image bar — a picture in a cell is one
    // selection, not two.
    var tblBar = document.createElement('div');
    // ── `--float` IS THE SHARED BEHAVIOUR; `--image` AND `--table` ARE HOOKS ──
    // If the table bar borrowed `--image` for its styling, `.editor__bar--image`
    // would match TWO elements, and anything looking for the image bar would
    // find both. A class that means "floats over a thing" is not a class that
    // means "belongs to a picture", and sharing one for both is the bug.
    tblBar.className = 'editor__bar editor__bar--float editor__bar--table';
    tblBar.setAttribute('role', 'toolbar');
    tblBar.setAttribute('aria-label', 'Table');
    tblBar.hidden = true;

    TABLE_TOOLS.forEach(function (tool) {
      if (tool.sep) {
        var hr = document.createElement('span');
        hr.className = 'editor__sep';
        hr.setAttribute('aria-hidden', 'true');
        tblBar.appendChild(hr);
        return;
      }
      var t = document.createElement('button');
      t.type = 'button';
      t.className = 'editor__btn' + (tool.text ? ' editor__btn--text' : '');
      t.setAttribute('aria-label', tool.title);
      var ticon = tool.icon ? iconNode(tool.icon) : null;
      if (ticon) {
        t.appendChild(ticon);
        t.setAttribute('data-tip', tool.title);   // glyph-only: a tip is allowed
      } else {
        t.textContent = tool.label;
      }
      t.dataset.tbl = tool.tbl;
      t.addEventListener('mousedown', function (e) {
        e.preventDefault();
        runTable(tool, surface, textarea);
        setSelectedTable(currentTable());
        textarea.value = htmlOut(surface);
      });
      tblBar.appendChild(t);
    });
    wrap.appendChild(tblBar);

    // ── THE TOOLBAR SAYS WHAT IS ALREADY ON ────────────────────────────────
    // Without this, a person cannot tell whether the word under the caret is
    // bold — they have to press the button and look. `queryCommandState` is the
    // same deprecated-but-universal API as `execCommand` and is the only way to
    // ask.
    //
    // It also keeps the link button's promise honest: inside a link its title
    // becomes "Remove link", because that is what pressing it will do.
    function refresh() {
      // In source view every formatting button is disabled and the toggle owns
      // its own state; recomputing here would re-enable them all.
      if (sourceMode) { return; }

      var linked = inLink();
      var block = currentBlock();
      var heading = /^h[2-6]$/.test(block);

      // ── THE MENU REPORTS THE BLOCK THE CARET IS IN ─────────────────────
      // A menu that does not follow the caret is a menu that lies: it would
      // say "Paragraph" while the caret sat in a heading, and choosing
      // "Paragraph" would then look like it did nothing.
      //
      // An unknown block — inside a table cell, or content the HTML view
      // introduced — leaves the menu on Paragraph rather than blank, because a
      // blank select reads as broken and Paragraph is what pressing it gives.
      if (blockSelect) {
        var known = BLOCK_MENU.some(function (p) { return p[0] === block; });
        blockSelect.value = known ? block : 'p';
      }

      // WHICH ALIGNMENT THE SELECTION ALREADY HAS. Reported from the first
      // block it touches: a selection spanning a centred and a left paragraph
      // has no single answer, and showing the first one is the same thing the
      // Bold button does with a mixed selection.
      var alignOn = '';
      var first = alignedBlocks(surface)[0];
      if (first) {
        TEXT_ALIGNS.forEach(function (c) {
          if (first.classList.contains(c)) { alignOn = c; }
        });
      }

      // ── INDENT AND OUTDENT ARE HONESTLY UNAVAILABLE OUTSIDE A LIST ─────
      // See the note on the tools. Outside a list `execCommand('indent')`
      // produces either a blockquote or an inline style the sanitiser strips,
      // so both are DISABLED there rather than left to appear to work.
      // Outdent needs a list item that is actually nested to have anything to
      // do, but `queryCommandEnabled` reports it as available at the top level
      // too, so the test is the DOM: is there a list above this list?
      var li = null;
      var n = window.getSelection() && window.getSelection().anchorNode;
      while (n && n !== surface) {
        if (n.nodeType === 1 && n.nodeName === 'LI') { li = n; break; }
        n = n.parentNode;
      }

      buttons.forEach(function (b) {
        // ── BOLD IN A HEADING IS A CONTROL THAT DOES NOTHING ─────────────
        // A heading is already bold, so `execCommand('bold')` inside one
        // UN-bolds it — and does so with `<span style="font-weight: normal">`,
        // which `Html\Sanitizer` strips because inline styles are how a paste
        // from Word carries a wall of formatting. Net effect: the author
        // presses Bold, the text does not change, and nothing says why.
        //
        // Disabled. The rule against a drawn control that cannot be used
        // applies to buttons as much as to content types — but a control that
        // is HONESTLY unavailable is not the same as one that is greyed out to
        // sell an upgrade.
        if (b.tool.cmd === 'bold') {
          // ── THE ACCESSIBLE NAME DOES NOT MOVE ────────────────────────────
          // Not "Bold — a heading is already bold" while the caret is in a
          // heading. Two things are wrong with that: a control's NAME is what
          // it is, not what state it is in — the disabled state already
          // announces itself — and anything selecting the button by its name
          // would break the moment the caret moved.
          //
          // The reason is worth saying, but not at the cost of the name. `B`
          // is a letter on the face of the button so it may not carry a
          // tooltip, which leaves the disabled state to speak for itself — and
          // it does: greyed out, in a heading, with the heading button lit
          // beside it.
          b.el.disabled = heading;
        }

        // Nesting only means anything inside a list. Outdent additionally needs
        // something to come out OF, which is a list ancestor above this one.
        if (b.tool.cmd === 'indent') {
          b.el.disabled = !li;
        }
        if (b.tool.cmd === 'outdent') {
          // ── BOTH SHAPES COUNT AS NESTED ──────────────────────────────────
          // This reads the LIVE surface, which holds whatever the browser made:
          // Chromium's `indent` puts the sub-list beside its item, so the item's
          // list sits inside another LIST. `htmlOut()` repairs that on the way
          // out, where the item's list sits inside an `li` instead. Both mean
          // "there is something to come out of", and testing for only one of
          // them leaves the button greyed out with somewhere to go.
          var up = li && li.parentNode ? li.parentNode.parentNode : null;
          b.el.disabled = !up || !/^(LI|UL|OL)$/.test(up.nodeName);
        }
        // A rule is a block, and a block cannot go inside a heading.
        if (b.tool.cmd === 'insertHorizontalRule') {
          b.el.disabled = heading;
        }

        var on = false;
        try {
          if (b.tool.cmd === 'bold' || b.tool.cmd === 'italic'
              || b.tool.cmd === 'strikeThrough') {
            on = document.queryCommandState(b.tool.cmd);
          } else if (b.tool.cmd === 'insertUnorderedList' || b.tool.cmd === 'insertOrderedList') {
            on = document.queryCommandState(b.tool.cmd);
          } else if (b.tool.cmd === 'formatBlock') {
            on = currentBlock() === b.tool.arg;
          } else if (b.tool.cmd === 'textAlign') {
            on = alignOn === b.tool.arg;
          } else if (b.tool.cmd === 'link') {
            on = !!linked;
            var word = linked ? 'Remove link' : 'Link';
            b.el.setAttribute('data-tip', word);
            b.el.setAttribute('aria-label', word);
          }
        } catch (err) { /* queryCommandState throws outside an editable */ }
        b.el.classList.toggle('is-on', !!on);
        b.el.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }

    // ── THE WORD COUNT ─────────────────────────────────────────────────────
    // Under the box, quiet, on the right. It is the one number a person
    // writing a page actually wants, and its absence is the kind of small
    // missing thing that makes a tool feel like a prototype.
    //
    // COUNTED FROM THE TEXT, not the markup — `textContent`, so tags never
    // count as words — and `\S+` rather than a split on spaces, which counts an
    // empty document as one word.
    var count = document.createElement('div');
    count.className = 'editor__count';
    count.setAttribute('aria-live', 'off');   // it changes on every keystroke
    /**
     * The words, with block boundaries counted as spaces.
     *
     * ── `textContent` ALONE IS WRONG, AND QUIETLY ──────────────────────────
     * Two paragraphs read as `OneTwo` — one word — and so does a nested list,
     * because nothing in the markup puts a space between the end of one block
     * and the start of the next. A count that is short by one every time the
     * author presses Enter is worse than no count: it is a number on the screen
     * that is confidently wrong.
     *
     * Measured, not assumed: "First line." + Enter + "Second line." reported 3.
     */
    function wordsIn(node) {
        var copy = node.cloneNode(true);
        var BLOCKS = 'p, div, li, h2, h3, h4, h5, h6, blockquote, pre,'
                   + ' figcaption, td, th, dt, dd';
        // BEFORE AND AFTER. A trailing space alone is not enough once blocks
        // NEST: in `<li>Second line here<ul><li>Two</li></ul></li>` the outer
        // item's space lands after the whole sub-list, so "here" and "Two" ran
        // together and the count was short by one. Measured — the visual view
        // said 5 and the HTML view said 4 on the same markup, which is what
        // exposed it.
        Array.prototype.forEach.call(copy.querySelectorAll(BLOCKS), function (el) {
            el.parentNode.insertBefore(document.createTextNode(' '), el);
            el.appendChild(document.createTextNode(' '));
        });
        // `<br>` is void — nothing may be appended INTO it, so the space goes
        // beside it.
        Array.prototype.forEach.call(copy.querySelectorAll('br'), function (el) {
            el.parentNode.insertBefore(document.createTextNode(' '), el);
        });
        var text = (copy.textContent || '').trim();
        return text ? text.split(/\s+/).length : 0;
    }

    function countWords() {
        var words;
        if (sourceMode) {
            // The HTML view is a textarea of markup. Parsed rather than
            // regex-stripped, so `&nbsp;` is a space and not five characters of
            // its own.
            var tmp = document.createElement('div');
            tmp.innerHTML = textarea.value;
            words = wordsIn(tmp);
        } else {
            words = wordsIn(surface);
        }
        // "Word count: 0" and not a bare number: the only version that says
        // what the number IS.
        count.textContent = 'Word count: ' + words;
    }

    textarea.parentNode.insertBefore(wrap, textarea);
    wrap.appendChild(head);
    wrap.appendChild(bar);
    wrap.appendChild(surface);
    wrap.appendChild(textarea);
    wrap.appendChild(count);

    // HIDDEN, NOT REMOVED. It is still the field that posts.
    textarea.classList.add('editor__source');

    // ── SOURCE MODE ────────────────────────────────────────────────────────
    // Not a second editor — the SAME textarea, shown again. It is the field
    // that posts either way, so switching views cannot lose anything: going to
    // source writes the surface's HTML out, coming back reads it in.
    //
    // The choice is remembered per browser. Somebody who works in HTML should
    // not have to press this on every page; somebody who never touches it never
    // sees a change. `localStorage` is exactly the right weight for a per-person
    // convenience — it is not shared, not durable, and nothing depends on it
    // being there.
    var sourceMode = false;
    try { sourceMode = localStorage.getItem('cms-editor-view') === 'source'; } catch (err) { /* private mode */ }

    /**
     * IMPORT MARKDOWN. A document, pasted or from a file, goes to the server
     * (`Content\MarkdownImport`), and what comes back goes where the caret was,
     * as anything typed there would. Nothing is saved until the entry is.
     *
     * The caret is remembered across the dialog, as for a picture: a modal takes
     * focus out of the surface and collapses it. In the HTML view the box keeps
     * its own. A caret outside the surface means the end of the body.
     */
    function importMarkdown() {
      var sel = window.getSelection();
      // WHERE THE CURSOR IS NOW, in either view: once the dialog has closed the
      // HTML view's box reads as if it were at the start.
      var at = null;
      if (sourceMode) {
        at = [textarea.selectionStart, textarea.selectionEnd];
      } else if (sel && sel.rangeCount && surface.contains(sel.anchorNode)) {
        at = caretOffset(surface);
      }
      window.Prompt.ask({
        title: 'Import Markdown',
        body: 'Paste the document, or choose or drop its file. It goes in where the cursor is, or fills an empty body. Nothing is saved until you save.',
        verb: 'Import',
        wide: true,
        fields: [{
          name: 'markdown', label: 'Markdown', type: 'textarea', rows: 14, required: true,
          file: { label: 'Choose a file', accept: '.md,.markdown,.mdown,.mkd,.txt,text/markdown,text/plain' }
        }]
      }).then(function (answer) {
        if (answer === null) { return null; }
        return window.CMS.api('/admin/markdown', { method: 'POST', body: { markdown: answer.markdown } }).then(function (made) {
          placeMarkdown(made, at);
          return made.missing && made.missing.length ? tellMissing(made.missing) : null;
        });
      }).catch(function (err) {
        return window.Prompt.ask({ title: 'That did not import', body: err.message, verb: 'Close', fields: [] });
      });
    }

    function placeMarkdown(made, at) {
      if (sourceMode) {
        var value = textarea.value;
        textarea.value = value.slice(0, at[0]) + made.html + value.slice(at[1]);
        textarea.selectionStart = textarea.selectionEnd = at[0] + made.html.length;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.focus();
      } else {
        // THROUGH `surfaceHtml()`, as a save's HTML coming back is: an embed in
        // the document is a block showing the provider's frame, and no script
        // runs. The white space between its blocks is the HTML view's, not
        // something to keep in a surface.
        var parsed = document.createElement('template');
        parsed.innerHTML = surfaceHtml(made.html);
        Array.prototype.slice.call(parsed.content.childNodes).forEach(function (n) {
          if (n.nodeType === 3 && !n.textContent.trim()) { parsed.content.removeChild(n); }
        });
        surface.focus();
        if (!surface.textContent.trim() && !surface.querySelector('img, hr, iframe, table')) {
          // AN EMPTY BODY BECOMES THE DOCUMENT, with no empty paragraph above it.
          surface.textContent = '';
          surface.appendChild(parsed.content);
          ensureBlock(surface);
          setCaretOffset(surface, Infinity);
        } else {
          setCaretOffset(surface, at === null ? Infinity : at);
          insertBlock(surface, parsed.content);
        }
        sync();
      }
      // AN EMPTY TITLE BOX IS FILLED, and told so, for whatever follows it (the
      // address made from a title). One the writer has filled is theirs.
      var box = textarea.form ? textarea.form.querySelector('input[name="title"]') : null;
      if (made.title && box && !box.value.trim()) {
        box.value = made.title;
        box.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }

    /** The pictures left out: files on the writer's computer, which the site does not have. */
    function tellMissing(missing) {
      var names = missing.map(function (m) { return m.alt ? m.src + ' (' + m.alt + ')' : m.src; });
      return window.Prompt.ask({
        title: 'Some pictures were left out',
        body: 'These are files on your computer, so the site does not have them. Add each one with Add image:\n\n' + names.join('\n'),
        verb: 'Close',
        fields: []
      });
    }

    function setSourceMode(on) {
        sourceMode = !!on;

        if (sourceMode) {
            textarea.value = htmlOut(surface);
        } else {
            // COMING BACK, THE TEXTAREA IS AUTHORITATIVE. Whatever was typed
            // as HTML becomes the surface — including markup the toolbar has no
            // button for, which is half the reason to have this view. Except a
            // frame: see `surfaceHtml()`.
            surface.innerHTML = surfaceHtml(textarea.value);
            ensureBlock(surface);
        }

        wrap.classList.toggle('is-source', sourceMode);

        // EVERY FORMATTING CONTROL IS DISABLED IN SOURCE VIEW. They act on the
        // surface, which is not on screen — pressing Bold would silently
        // format something the author cannot see, and the change would be
        // thrown away the moment they typed in the textarea.
        // Every formatting control acts on the surface, which is not on
        // screen in source view — pressing Bold would format something the
        // author cannot see, and the change would be thrown away the moment
        // they typed in the textarea.
        buttons.forEach(function (b) { b.el.disabled = sourceMode; });
        // The block menu is a formatting control like the rest of them, and it
        // was left enabled the first time — choosing "Heading 3" in source view
        // reformatted a surface nobody could see, and the change went away the
        // next time the textarea was typed in.
        if (blockSelect) { blockSelect.disabled = sourceMode; }

        // The active tab is where you ARE. `aria-selected` carries it for a
        // screen reader; `.is-active` is core.css's own state class.
        ['rich', 'source'].forEach(function (name) {
            var on = (name === 'source') === sourceMode;
            viewBtns[name].classList.toggle('is-active', on);
            viewBtns[name].setAttribute('aria-selected', on ? 'true' : 'false');
        });

        try { localStorage.setItem('cms-editor-view', sourceMode ? 'source' : 'rich'); } catch (err) { /* private mode */ }
        // RECOUNT ON THE SWITCH. The two views count from different sources —
        // the surface and the markup — and neither fires `input` when the tab
        // is pressed, so the number was whatever the other view had left there.
        countWords();
        (sourceMode ? textarea : surface).focus();
    }

    // ── EIGHT HANDLES, AND WHY THEY ARE NOT IN THE SURFACE ─────────────────
    //
    // The surface is `contenteditable`. Anything appended to it IS content: it
    // would appear in the HTML view, go into the textarea, and be posted. So
    // the bar and the handles are siblings of the surface, positioned over the
    // picture from its `getBoundingClientRect()`.
    //
    // That costs a reposition on scroll and resize, which is the whole reason
    // `placeOverlays()` exists. Putting them inside would have cost the
    // document's correctness, and `htmlOut()` already has a note about the last
    // time editing state reached the markup.
    var handles = document.createElement('div');
    handles.className = 'editor__handles';
    handles.hidden = true;
    handles.setAttribute('aria-hidden', 'true');   // the bar is the operable UI
    ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'].forEach(function (dir) {
        var h = document.createElement('span');
        h.className = 'editor__handle editor__handle--' + dir;
        h.dataset.handle = dir;
        handles.appendChild(h);
    });
    // MOUNTED HERE, not up with the bar. `var handles` is hoisted but its VALUE
    // is not — appending it before this line appended `undefined` and threw on
    // page load, so the editor never built and every screen with one was a
    // plain textarea. Caught by loading the page and reading the console, which
    // is the only thing that could have.
    wrap.appendChild(handles);

    // ── WHICH IMAGE IS SELECTED, IF ANY ────────────────────────────────────
    var selectedImage = null;

    /**
     * Put the floating bar and the handles over the selected picture.
     *
     * Measured against `.editor`'s own box rather than the page's, so the
     * numbers are already in the coordinate space the absolute positioning
     * uses and nothing has to know about the page's scroll.
     *
     * THE BAR GOES ABOVE THE PICTURE, or inside its top edge when there is no
     * room above — a bar drawn off the top of the editor is a bar with no
     * controls in it.
     */
    /** Put `bar` above `target`, or just inside its top when there is no room. */
    function floatBarOver(bar, target) {
        var b    = target.getBoundingClientRect();
        var host = wrap.getBoundingClientRect();
        var top  = b.top - host.top;
        var left = b.left - host.left;
        var barH = bar.offsetHeight || 30;
        var above = top - barH - 6;
        bar.style.top  = (above > 0 ? above : top + 6) + 'px';
        bar.style.left = left + 'px';
        return { top: top, left: left, box: b };
    }

    function placeOverlays() {
        if (selectedTable) {
            floatBarOver(tblBar, selectedTable);
        }
        if (!selectedImage) { return; }

        // The FIGURE's box when there is one — the handles frame what the
        // reader sees as the picture, and that includes its caption.
        var box = selectedImage.closest('figure') || selectedImage;
        var at  = floatBarOver(imgBar, box);

        handles.style.top    = at.top + 'px';
        handles.style.left   = at.left + 'px';
        handles.style.width  = at.box.width + 'px';
        handles.style.height = at.box.height + 'px';
    }

    // ── WHICH TABLE THE CARET IS IN, IF ANY ────────────────────────────────
    var selectedTable = null;

    function currentTable() {
        var sel = window.getSelection();
        var n = sel && sel.anchorNode;
        while (n && n !== surface) {
            if (n.nodeType === 1 && n.nodeName === 'TABLE') { return n; }
            n = n.parentNode;
        }
        return null;
    }

    function setSelectedTable(table) {
        selectedTable = table && surface.contains(table) ? table : null;
        // NEVER BOTH AT ONCE. A picture inside a cell is one selection; two
        // floating bars over the same few pixels is two controls fighting for
        // the same click.
        tblBar.hidden = !selectedTable || !!selectedImage;
        if (!selectedTable) { return; }

        // ── THE LAST ROW AND THE LAST COLUMN CANNOT GO ───────────────────────
        // A table with no cells is not a table, it is an empty element the
        // author then has to find and delete. Honestly disabled, with Delete
        // beside them for when the whole thing should go.
        //
        // HERE AND NOT IN `refreshImage()`, where the first draft put it — that
        // runs only while a PICTURE is selected, so the two buttons would have
        // been recomputed exactly when there was no table to recompute them for.
        var rows = selectedTable.querySelectorAll('tr').length;
        var firstRow = selectedTable.querySelector('tr');
        var cols = firstRow ? firstRow.children.length : 0;
        var btn = function (name) { return tblBar.querySelector('[data-tbl="' + name + '"]'); };
        if (btn('row-del')) { btn('row-del').disabled = rows <= 1; }
        if (btn('col-del')) { btn('col-del').disabled = cols <= 1; }

        placeOverlays();
    }

    function setSelectedImage(img) {
        // The outline is a CLASS on the element, not a browser selection: a
        // contenteditable's own selection around an image is a thin caret line
        // that nobody can see, which is why clicking a picture in a plain
        // contenteditable feels like nothing happened.
        Array.prototype.forEach.call(
            surface.querySelectorAll('.is-selected'),
            function (e) { e.classList.remove('is-selected'); }
        );

        selectedImage = img && surface.contains(img) ? img : null;
        if (selectedImage) { selectedImage.classList.add('is-selected'); }

        // ── THE WRITING BAR STAYS ────────────────────────────────────────
        // Selecting a picture does not swap one bar for the other: that would
        // make the formatting controls vanish while an image is selected, and
        // need a "Done" button to bring them back. A small bar floats OVER the
        // picture instead, and the toolbar is left alone.
        imgBar.hidden  = !selectedImage;
        handles.hidden = !selectedImage;

        if (selectedImage) {
            refreshImage();
            placeOverlays();
        }
        textarea.value = htmlOut(surface);
    }

    /** The image bar reports the alignment the picture already has. */
    function refreshImage() {
        if (!selectedImage) { return; }
        var box = alignHost(selectedImage);
        imgButtons.forEach(function (b) {
            if (b.tool.img !== 'align') { return; }
            var on = box.classList.contains(b.tool.arg)
                  || (b.tool.arg === 'align-none' && !hasAnyAlign(box));
            b.el.classList.toggle('is-on', on);
            b.el.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        // THE DETAILS BUTTON LIGHTS WHEN THERE IS SOMETHING TO SEE — a
        // caption, a link, or a set width. Without it the only way to find out
        // whether a picture already carries any of them is to open the dialog,
        // which is the same problem the Bold button's pressed state solves.
        var det = imgButtons.filter(function (b) { return b.tool.img === 'details'; })[0];
        if (det) {
            var carries = !!selectedImage.closest('figure')
                       || !!selectedImage.getAttribute('width')
                       || (selectedImage.parentNode
                           && selectedImage.parentNode.nodeName === 'A');
            det.el.classList.toggle('is-on', !!carries);
        }
    }

    // THE SURFACE SCROLLS UNDER THE OVERLAYS, so they have to follow it. A bar
    // left behind at the old offset is a control pointing at the wrong picture,
    // which is worse than no bar.
    surface.addEventListener('scroll', placeOverlays);
    window.addEventListener('resize', placeOverlays);

    // CLICKING A PICTURE SELECTS IT; clicking anything else lets it go.
    surface.addEventListener('click', function (e) {
        setSelectedImage(e.target && e.target.nodeName === 'IMG' ? e.target : null);
    });
    // Escape is the way out of anything in this admin.
    surface.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && selectedImage) {
            e.preventDefault();
            setSelectedImage(null);
        }
    });
    // ── AN EMBED IS DELETED WHOLE ──────────────────────────────────────────
    // Nothing in the block can be typed into, so the caret cannot step into
    // it either, and left to the browsers, Backspace at the start of the line
    // after it (or Delete at the end of the line before it) pulled that line
    // past the block (Firefox) or took the block and joined the lines either
    // side (Chromium). The first press selects the embed — outlined, as a
    // clicked picture is — and the second deletes it, and the caret is where
    // it was. An empty line beside it is only a place for the caret, and goes
    // with it when there is another line to go to.
    //
    // THE SECOND PRESS IS OURS, not the browser's, for the same reason, and so
    // is any other key while it is selected: a letter took the block and joined
    // the lines (Chromium) or stood bare between them (Firefox). Typing or
    // moving the caret means the author has gone back to the words, so the
    // caret goes back first and the key then does what it does.
    var besideEmbed = null;

    // The start or end of a line, and of a list or a table its first or last
    // item: Firefox types where a caret is put, even between a list's items.
    function lineEdge(line, atEnd) {
        var inner;
        while ((inner = atEnd ? line.lastElementChild : line.firstElementChild)
               && /^(UL|OL|LI|DL|DT|DD|TABLE|THEAD|TBODY|TFOOT|TR|TH|TD)$/.test(inner.nodeName)) {
            line = inner;
        }
        var at = document.createRange();
        at.selectNodeContents(line);
        at.collapse(!atEnd);
        return at;
    }

    surface.addEventListener('keydown', function (e) {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount) { return; }
        var deleting = e.key === 'Backspace' || e.key === 'Delete';
        var picked = surface.querySelector('.editor__embed.is-selected');
        if (picked && sel.getRangeAt(0).intersectsNode(picked)) {
            if (/^(Shift|Control|Alt|AltGraph|Meta|CapsLock)$/.test(e.key)) { return; }
            if (!deleting) {
                restoreRange(besideEmbed.caret, surface, null);
                return;
            }
            e.preventDefault();
            var from = besideEmbed.line;
            var caret = besideEmbed.caret;
            surface.removeChild(picked);
            if (!from.textContent.trim() && !from.querySelector('img, iframe, hr')) {
                var words = function (el) { return el && !el.matches('.editor__embed') ? el : null; };
                var before = words(from.previousElementSibling);
                var after = words(from.nextElementSibling);
                var land = e.key === 'Delete' ? (before || after) : (after || before);
                if (land) {
                    surface.removeChild(from);
                    caret = lineEdge(land, land === before);
                }
            }
            restoreRange(caret, surface, null);
            sync();
            return;
        }
        if (!deleting || !sel.isCollapsed) { return; }
        var line = sel.anchorNode;
        while (line && line.parentNode !== surface) { line = line.parentNode; }
        var back = e.key === 'Backspace';
        var embed = line && (back ? line.previousElementSibling : line.nextElementSibling);
        if (!embed || !embed.matches('.editor__embed')) { return; }
        var rest = document.createRange();
        rest.selectNodeContents(line);
        if (back) {
            rest.setEnd(sel.anchorNode, sel.anchorOffset);
        } else {
            rest.setStart(sel.anchorNode, sel.anchorOffset);
        }
        var contents = rest.cloneContents();
        if (contents.textContent !== '' || contents.querySelector('img, iframe, hr')) { return; }
        e.preventDefault();
        besideEmbed = { line: line, caret: sel.getRangeAt(0).cloneRange() };
        var whole = document.createRange();
        whole.selectNode(embed);
        restoreRange(whole, surface, null);
        embed.classList.add('is-selected');
        var release = function () {
            var now = window.getSelection();
            if (!embed.isConnected || !now.rangeCount || now.isCollapsed || !now.getRangeAt(0).intersectsNode(embed)) {
                embed.classList.remove('is-selected');
                document.removeEventListener('selectionchange', release);
            }
        };
        document.addEventListener('selectionchange', release);
    });

    // Typing or moving the caret means the author has gone back to the words.
    surface.addEventListener('keyup', function () {
        if (selectedImage) { setSelectedImage(null); }
    });

    // ── DRAG A HANDLE TO RESIZE ────────────────────────────────────────────
    //
    // ALWAYS PROPORTIONAL. A picture stretched on one axis is a picture the
    // owner has damaged, and no editor worth the name offers it as a gesture.
    // Which handle is grabbed decides the DIRECTION, not the axes.
    //
    // `width` and `height` ATTRIBUTES, not a style: `Html\Sanitizer` allows
    // both on `img` and strips every inline style, so a style here would be a
    // resize that survives until Save. Both are written, because a width alone
    // makes the browser reflow the page as the image loads.
    (function () {
        var drag = null;

        handles.addEventListener('mousedown', function (e) {
            var h = e.target.closest('[data-handle]');
            if (!h || !selectedImage) { return; }
            e.preventDefault();
            var b = selectedImage.getBoundingClientRect();
            drag = {
                dir: h.dataset.handle,
                x: e.clientX,
                startW: b.width,
                ratio: b.height / b.width,
                // The picture's own pixels are the ceiling. Scaling past them
                // is how a 400px logo ends up blurred across a whole column.
                maxW: selectedImage.naturalWidth || 4000
            };
            document.body.classList.add('is-resizing');
        });

        document.addEventListener('mousemove', function (e) {
            if (!drag) { return; }
            // West and north handles grow as the pointer moves LEFT.
            var sign = /w$/.test(drag.dir) || drag.dir === 'n' ? -1 : 1;
            var w = Math.round(drag.startW + (e.clientX - drag.x) * sign);
            w = Math.max(40, Math.min(w, drag.maxW));
            selectedImage.setAttribute('width', String(w));
            selectedImage.setAttribute('height', String(Math.round(w * drag.ratio)));
            placeOverlays();
        });

        document.addEventListener('mouseup', function () {
            if (!drag) { return; }
            drag = null;
            document.body.classList.remove('is-resizing');
            textarea.value = htmlOut(surface);
        });
    }());

    var sync = function () {
        if (!sourceMode) { textarea.value = htmlOut(surface); }
        countWords();
    };
    surface.addEventListener('input', function () { sync(); refresh(); });
    // The HTML view is a textarea, so it has no `input` on the surface — and a
    // word count that freezes the moment you switch views is worse than none.
    textarea.addEventListener('input', countWords);
    surface.addEventListener('blur', sync);
    // The caret moving is not an `input` event, and the toolbar has to follow
    // it — otherwise it reports the state of wherever you last typed.
    surface.addEventListener('keyup', refresh);
    surface.addEventListener('mouseup', refresh);
    surface.addEventListener('focus', refresh);

    // ── THE TABLE BAR FOLLOWS THE CARET, NOT A CLICK ───────────────────────
    // A table is not selected the way a picture is — you are simply typing
    // inside one — so the bar appears whenever the caret is in a cell and goes
    // when it leaves. Bound to the same three events the toolbar's own state
    // uses, so it can never disagree with them about where the caret is.
    var followTable = function () { setSelectedTable(currentTable()); };
    surface.addEventListener('keyup', followTable);
    surface.addEventListener('mouseup', followTable);
    surface.addEventListener('focus', followTable);
    surface.addEventListener('input', followTable);
    // LEAVING THE EDITOR PUTS IT AWAY. A bar floating over a table on a screen
    // whose caret is in the SEO description is a control pointing at nothing.
    surface.addEventListener('blur', function () { setSelectedTable(null); });

    // TYPING IN SOURCE VIEW IS THE VALUE. The textarea is the field that posts,
    // so nothing has to be copied anywhere — but the surface must be rebuilt
    // from it on the way back, which `setSourceMode(false)` does.
    refresh();
    countWords();
    if (sourceMode) { setSourceMode(true); }

    // ── PASTE ARRIVES AS TEXT, WITH LINE BREAKS KEPT ──────────────────────
    // A paste from Word or a web page carries `<style>`, `<o:p>`, inline fonts
    // and a class for every span. The sanitiser would strip all of it on save,
    // which means what the author SEES in the editor and what they GET after
    // saving would differ — the worst kind of surprise, because it happens
    // after they have stopped looking.
    //
    // So the paste is flattened here, and the two agree.
    surface.addEventListener('paste', function (e) {
      e.preventDefault();
      var text = (e.clipboardData || window.clipboardData).getData('text/plain');
      // AN EMBED CODE — copied from a provider's Share or Embed option — goes
      // in AS THE EMBED, a block of its own, the provider's own frame showing.
      var embed = pastedEmbed(text);
      if (embed) {
        insertBlock(surface, embed);
        sync();
        return;
      }
      // Blank line => new paragraph; single newline => a break. This is what a
      // person means by the shape of what they pasted.
      var html = text.split(/\n{2,}/).map(function (para) {
        return '<p>' + escapeHtml(para).replace(/\n/g, '<br>') + '</p>';
      }).join('');
      document.execCommand('insertHTML', false, html);
      sync();
    });

    // Ctrl/Cmd+B and +I, because every editor has them and their absence reads
    // as the editor being broken rather than as a decision.
    surface.addEventListener('keydown', function (e) {
      if (!(e.ctrlKey || e.metaKey) || e.altKey) { return; }
      var k = e.key.toLowerCase();
      // ── UNDERLINE IS A SHORTCUT AND NOT A BUTTON ─────────────────────────
      // No button, because underlined text on the web reads as a link; the
      // shortcut stays, so this handler knows U as well as B and I. `<u>` is in
      // `Html\Sanitizer::TAGS`, so what it produces survives the save.
      var CMDS = { b: 'bold', i: 'italic', u: 'underline' };
      if (CMDS[k]) {
        e.preventDefault();
        // The keyboard shortcut obeys the same rule as the button — otherwise
        // Ctrl+B in a heading would still emit the style the toolbar refuses to.
        if (k === 'b' && /^h[2-6]$/.test(currentBlock())) { return; }
        document.execCommand(CMDS[k]);
        sync();
        refresh();
      }
    });

    // ENTER MAKES A PARAGRAPH, NOT A <div>. Chromium's default inside a
    // contenteditable is a `<div>` per line, which the sanitiser allows and
    // which then styles as prose with no spacing between paragraphs. Setting
    // this once tells the browser to use `<p>`.
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (err) { /* not fatal */ }
  }

  /**
   * @param {function} [after]  run once the command has finished, INCLUDING the
   *   ones that finish inside a dialog's Promise. `build()` passes a callback
   *   that puts the toolbars back in step with wherever the caret ended up —
   *   the bars follow `keyup` and `mouseup`, and a caret moved by code fires
   *   neither. Without it, inserting a table left its own bar hidden until the
   *   author clicked into a cell, which is a control that appears only after
   *   you have found it another way.
   */
  function run(tool, surface, textarea, after) {
    var done = function () { if (typeof after === 'function') { after(); } };
    surface.focus();

    // ONLY FOR A COLLAPSED CARET. With text SELECTED, the commands keep the
    // selection correctly and restoring a single offset would throw the
    // selection away — the author would embolden three words and lose them
    // from the selection, which is worse than the bug being fixed.
    var sel = window.getSelection();
    var keep = (sel && sel.isCollapsed) ? caretOffset(surface) : null;

    if (tool.cmd === 'link') {
      // ── ONE BUTTON, BOTH DIRECTIONS ──────────────────────────────────────
      if (inLink()) {
        document.execCommand('unlink');
      } else {
        // ── THE ADMIN'S OWN DIALOG, NOT window.prompt ────────────────────
        // A native prompt cannot be styled or themed. `prompt.js` is
        // `confirm.js`'s sibling and gives the same Promise contract the
        // native call had.
        //
        // THE CARET IS REMEMBERED ACROSS IT. Opening a modal moves focus, which
        // collapses the selection — so the link would be created at the start
        // of the document rather than around the words that were selected.
        var at = caretOffset(surface);
        var range = saveRange();
        window.Prompt.ask({
          title: 'Add a link',
          label: 'Address',
          value: 'https://',
          verb: 'Add link'
        }).then(function (url) {
          if (url === null || !url.trim()) { return; }
          surface.focus();
          restoreRange(range, surface, at);
          document.execCommand('createLink', false, url.trim());
          textarea.value = htmlOut(surface);
          done();
        });
      }
    } else if (tool.cmd === 'formatBlock') {
      // ── A BUTTON TOGGLES; THE MENU SETS ──────────────────────────────────
      // Pressing a Quote button on something already a quote returns it to a
      // paragraph, because that is the only way back from a button. Choosing
      // "Heading 3" from the menu while already on Heading 3 must NOT turn it
      // into a paragraph — the menu already shows what you are on, so a toggle
      // there is a control that does the opposite of what it says. `force`
      // is the menu.
      document.execCommand('formatBlock', false,
                           (!tool.force && currentBlock() === tool.arg) ? 'p' : tool.arg);
    } else if (tool.cmd === 'textAlign') {
      // ── ALIGNMENT IS A CLASS ON THE BLOCK, NOT execCommand ───────────────
      // `execCommand('justifyCenter')` writes `style="text-align:center"`, and
      // `Html\Sanitizer` strips every inline style — so the browser's own
      // alignment commands are exactly the shape of control that appears to
      // work and loses the change on save. The class vocabulary is
      // `Sanitizer::TEXT_CLASSES` and the theme owns what it means.
      //
      // EVERY BLOCK THE SELECTION TOUCHES, not just the one the caret is in:
      // selecting three paragraphs and pressing Centre must centre three.
      alignedBlocks(surface).forEach(function (el) {
        var already = el.classList.contains(tool.arg);
        TEXT_ALIGNS.forEach(function (c) { el.classList.remove(c); });
        // Pressing the alignment a block already has takes it off — back to
        // whatever the theme's default is, which for left-to-right prose is
        // the same as `text-left` and is one fewer class in the markup.
        if (!already) { el.classList.add(tool.arg); }
        if (el.getAttribute('class') === '') { el.removeAttribute('class'); }
      });
    } else if (tool.cmd === 'insertUnorderedList' || tool.cmd === 'insertOrderedList') {
      // ── A LIST INSIDE A HEADING IS NOT A THING ─────────────────────────
      // `execCommand('insertUnorderedList')` with the caret in an `<h2>`
      // produces `<h2><ul><li>…</li></ul></h2>`, which is invalid — a heading
      // may contain inline content only — and which browsers then re-parse
      // however they like. Stored, it comes back as exactly that.
      //
      // The heading goes back to a paragraph first, so the list is made where a
      // list can live. Turning a heading into a list is what the author asked
      // for; keeping it a heading was never one of the options.
      if (/^h[2-6]$/.test(currentBlock())) {
        document.execCommand('formatBlock', false, 'p');
      }
      document.execCommand(tool.cmd, false, null);
    } else if (tool.cmd === 'image') {
      // ── THE LIBRARY, NOT A URL BOX ─────────────────────────────────────
      // `CmsMedia.pick()` is `entry-form.js` lending out the one media
      // dialog this admin has — the same one the Featured image picker opens,
      // with the same upload button in its footer. Building a second one would
      // mean two markup sources and two upload paths to keep in step.
      //
      // The selection is remembered across the dialog, because opening a modal
      // takes focus out of the surface and collapses it — so the image is
      // inserted where the caret WAS, not at the top.
      var at = caretOffset(surface);
      if (window.CmsMedia && typeof window.CmsMedia.pick === 'function') {
        window.CmsMedia.pick(function (chosen) {
          if (!chosen || !chosen.url) { return; }
          // ALT TEXT IS ASKED FOR, not left empty. An image with no alt is
          // invisible to a screen reader and to a search engine, and the moment
          // to write it is while you are looking at the picture.
          //
          // AN EMPTY ANSWER AND A CANCEL ARE DIFFERENT. A decorative image
          // should have `alt=""`, so "" is a real answer; `null` means the
          // author changed their mind and no image is inserted at all.
          window.Prompt.ask({
            title: 'Describe this image',
            body: 'For people who cannot see it, and for search results. '
                + 'Leave it blank if the image is decorative.',
            label: 'Description',
            placeholder: 'Two members at the front desk',
            verb: 'Insert image'
          }).then(function (alt) {
            if (alt === null) { return; }
            surface.focus();
            setCaretOffset(surface, at);
            var img = document.createElement('img');
            img.src = chosen.url;
            img.alt = alt;
            insertNode(surface, img);
            textarea.value = htmlOut(surface);
            done();
          });
        });
      }
    } else if (tool.cmd === 'char') {
      var charAt = caretOffset(surface);
      var charRange = saveRange();
      window.Prompt.ask({
        title: 'Insert a character',
        label: 'Character',
        verb: 'Insert',
        fields: [{
          name: 'ch',
          label: 'Character',
          type: 'select',
          options: CHARACTERS,
          help: 'The ones a keyboard has no key for. Typed straight into the '
              + 'text, so they are ordinary characters afterwards.'
        }]
      }).then(function (v) {
        if (v === null || !v.ch) { return; }
        surface.focus();
        restoreRange(charRange, surface, charAt);
        // `insertText`, NOT `insertHTML`: these are CHARACTERS, and putting
        // them through an HTML parser would turn a typed `&` into an entity and
        // an entity into something else again.
        document.execCommand('insertText', false, v.ch);
        textarea.value = htmlOut(surface);
        done();
      });
    } else if (tool.cmd === 'table') {
      // ── ASKED FOR, NOT GUESSED ───────────────────────────────────────────
      // Every editor that inserts a default 3x3 makes the author delete rows.
      // The many-field prompt exists now, so the question costs one dialog and
      // saves the deleting. A header row is the default because a table without
      // one is a grid of numbers a screen reader cannot announce.
      var tableAt = caretOffset(surface);
      window.Prompt.ask({
        title: 'Insert a table',
        verb: 'Insert',
        fields: [
          { name: 'rows', label: 'Rows', type: 'number', value: '3', min: 1,
            help: 'Not counting the header row.' },
          { name: 'cols', label: 'Columns', type: 'number', value: '3', min: 1 },
          { name: 'head', label: 'Header row', type: 'select', value: 'yes',
            options: [['yes', 'Yes'], ['no', 'No']],
            help: 'A header row is what lets a screen reader say which column a '
                + 'cell belongs to. Leave it on unless the table is a layout.' }
        ]
      }).then(function (v) {
        if (v === null) { return; }
        var rows = Math.max(1, Math.min(parseInt(v.rows, 10) || 1, 50));
        var cols = Math.max(1, Math.min(parseInt(v.cols, 10) || 1, 20));

        var table = document.createElement('table');
        if (v.head === 'yes') {
          var thead = document.createElement('thead');
          var hr = document.createElement('tr');
          for (var c = 0; c < cols; c++) {
            var th = document.createElement('th');
            th.setAttribute('scope', 'col');
            th.appendChild(document.createElement('br'));
            hr.appendChild(th);
          }
          thead.appendChild(hr);
          table.appendChild(thead);
        }
        var tbody = document.createElement('tbody');
        for (var r = 0; r < rows; r++) {
          var tr = document.createElement('tr');
          for (var c2 = 0; c2 < cols; c2++) {
            var td = document.createElement('td');
            // `<br>` IN EVERY CELL, for the same reason `ensureBlock()` puts one
            // in an empty paragraph: a cell with nothing in it has no height and
            // no place to put the caret, so the table renders as a row of lines.
            td.appendChild(document.createElement('br'));
            tr.appendChild(td);
          }
          tbody.appendChild(tr);
        }
        table.appendChild(tbody);

        surface.focus();
        setCaretOffset(surface, tableAt);
        insertNode(surface, table);
        // THE CARET GOES INTO THE FIRST CELL. Left after the table, the first
        // thing typed lands underneath it and the author has to click back in.
        var first = table.querySelector('th, td');
        if (first) {
          var range = document.createRange();
          range.selectNodeContents(first);
          range.collapse(true);
          var sel2 = window.getSelection();
          sel2.removeAllRanges();
          sel2.addRange(range);
        }
        textarea.value = htmlOut(surface);
        done();
      });
    } else if (tool.cmd === 'formatInline') {
      // `<code>` HAS NO execCommand. Wrapping the selection by hand is the one
      // place this file does its own DOM work, and it is deliberately the
      // simplest possible version: wrap what is selected, or do nothing.
      // Anything cleverer — merging adjacent code spans, splitting across
      // paragraphs — is the Range manipulation the header explains staying out
      // of, and getting it wrong loses an author's text.
      var sel = window.getSelection();
      if (sel && !sel.isCollapsed && sel.rangeCount) {
        var range = sel.getRangeAt(0);
        var el = document.createElement(tool.arg);
        try {
          range.surroundContents(el);
        } catch (err) {
          // `surroundContents` throws when the selection crosses an element
          // boundary. Falling back to the text loses the formatting inside it,
          // which is the correct trade for `code` — code is plain by nature.
          el.textContent = range.toString();
          range.deleteContents();
          range.insertNode(el);
        }
      }
    } else {
      // ── `undefined`, NOT `null` ──────────────────────────────────────────
      // `execCommand('insertHorizontalRule', false, null)` uses the third
      // argument as the new element's ID, and `null` arrives as the STRING
      // "null" — so the rule went in as `<hr id="null">`. The sanitiser strips
      // `id`, so nothing wrong could ever be stored; the editor's own markup
      // simply disagreed with what a save would produce, which is the promise
      // this file exists to keep.
      if (tool.arg) {
        document.execCommand(tool.cmd, false, tool.arg);
      } else {
        document.execCommand(tool.cmd, false);
      }
    }

    // TIDY BEFORE RESTORING THE CARET, not after — `unnestBlocks` moves nodes,
    // which would invalidate a range set beforehand. The character offset is
    // unaffected by the move, which is why it is the thing being remembered.
    //
    // ── AND THE REPAIRS HAPPEN ON THE WAY OUT, NOT IN THE SURFACE ──────────
    // Run here, on the live DOM, straight after `execCommand`, they would MOVE
    // NODES behind the browser's back, and the undo stack knows nothing about
    // it: pressing Undo after an indent produced `<ul><li>One</li></ul>` —
    // "Two" was GONE. Losing the author's words on the one gesture they press
    // when they want their words back is the worst thing in this file.
    //
    // Doing the repair through `execCommand('insertHTML')` so it would join the
    // undo step is worse: `insertHTML` re-parses, and a list comes back as
    // `<p><ul></ul></p><p><li></li></p>` plus a `<blockquote>` carrying inline
    // margins. Measured, not assumed.
    //
    // So the surface keeps whatever the browser made — which RENDERS correctly,
    // that was never the problem — and `htmlOut()` repairs its clone. The
    // textarea, the HTML view and the save all see the corrected markup, native
    // undo is untouched, and nothing the author typed can be lost.
    ensureBlock(surface);
    if (keep !== null) { setCaretOffset(surface, keep); }

    textarea.value = htmlOut(surface);
    done();
  }

  /**
   * What the table bar's buttons do.
   *
   * ── EVERY ONE OF THEM IS RELATIVE TO THE CARET ─────────────────────────────
   * "Add a row" means after the row you are in, not at the end; "delete a
   * column" means the one you are in. A control that acts on the end of the
   * table while the caret is in the middle is a control whose effect the author
   * has to go and look for.
   *
   * The LAST ROW AND THE LAST COLUMN CANNOT BE DELETED. A table with no cells
   * is not a table, it is an empty element the author then has to find and
   * remove — so those two buttons disable themselves, and Delete is there for
   * when the whole thing should go.
   */
  /** Put the caret at the start of a cell, if there is one. */
  function caretInto(cell) {
    if (!cell) { return; }
    var range = document.createRange();
    range.selectNodeContents(cell);
    range.collapse(true);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  function runTable(tool, surface, textarea) {
    var sel = window.getSelection();
    var cell = null, n = sel && sel.anchorNode;
    while (n && n !== surface) {
      if (n.nodeType === 1 && /^(TD|TH)$/.test(n.nodeName)) { cell = n; break; }
      n = n.parentNode;
    }
    if (!cell) { return; }

    var row   = cell.parentNode;
    var table = cell.closest('table');
    if (!table) { return; }
    var index = Array.prototype.indexOf.call(row.children, cell);

    var blankLike = function (model) {
      var c = document.createElement(model.nodeName.toLowerCase());
      if (model.nodeName === 'TH') { c.setAttribute('scope', 'col'); }
      c.appendChild(document.createElement('br'));
      return c;
    };

    if (tool.tbl === 'row-add') {
      var copy = document.createElement('tr');
      Array.prototype.forEach.call(row.children, function (c) {
        // A NEW ROW IS ALWAYS BODY CELLS, even when added under the header —
        // a second header row is a different table, not another row of this one.
        var made = document.createElement('td');
        made.appendChild(document.createElement('br'));
        copy.appendChild(made);
      });
      // Under the header means the TOP of the body, not inside `thead`.
      if (row.parentNode.nodeName === 'THEAD') {
        var body = table.querySelector('tbody') || table;
        body.insertBefore(copy, body.firstChild);
      } else {
        row.parentNode.insertBefore(copy, row.nextSibling);
      }
      return;
    }

    if (tool.tbl === 'row-del') {
      var section = row.parentNode;
      if (table.querySelectorAll('tr').length <= 1) { return; }
      var nextRow = row.nextElementSibling || row.previousElementSibling;
      section.removeChild(row);
      if (!section.children.length && section !== table) {
        section.parentNode.removeChild(section);
      }
      // ── THE CARET HAS TO LAND SOMEWHERE THAT STILL EXISTS ────────────────
      // It was inside the row that just went, so it would be left pointing into
      // a detached node — which means `currentTable()` finds nothing, the bar
      // disappears, and the author has to click back into the table to delete a
      // second row.
      //
      // The row BELOW, or the one above when there was none: the same column,
      // so the caret moves the shortest distance it can.
      var landing = nextRow || table.querySelector('tr');
      caretInto(landing ? (landing.children[index] || landing.children[0]) : null);
      return;
    }

    if (tool.tbl === 'col-add') {
      Array.prototype.forEach.call(table.querySelectorAll('tr'), function (r) {
        var at = r.children[index];
        var made = blankLike(at || r.children[r.children.length - 1] || cell);
        r.insertBefore(made, at ? at.nextSibling : null);
      });
      return;
    }

    if (tool.tbl === 'col-del') {
      if (row.children.length <= 1) { return; }
      Array.prototype.forEach.call(table.querySelectorAll('tr'), function (r) {
        if (r.children[index]) { r.removeChild(r.children[index]); }
      });
      // Same as above: the cell the caret was in has gone. Its neighbour to the
      // right took its index, or there is none and the one before it is last.
      caretInto(row.children[index] || row.children[row.children.length - 1]);
      return;
    }

    if (tool.tbl === 'remove') {
      table.parentNode.removeChild(table);
      ensureBlock(surface);
    }
  }

  var ALIGNS = ['align-left', 'align-center', 'align-right', 'align-none'];

  /**
   * The element the alignment belongs on: the outermost thing wrapping the
   * picture.
   *
   * A picture can be bare, inside an `<a>`, inside a `<figure>`, or inside
   * both. Floating the `img` while the figure around it does not float puts
   * the caption in one place and the picture in another; floating the `img`
   * inside an anchor floats it out of its own link box.
   */
  function alignHost(img) {
    var fig = img.closest('figure');
    if (fig) { return fig; }
    return img.parentNode && img.parentNode.nodeName === 'A' ? img.parentNode : img;
  }

  /** Move any alignment class from `from` onto `to`, and report what moved. */
  function moveAlign(from, to) {
    ALIGNS.forEach(function (a) {
      if (from.classList && from.classList.contains(a)) {
        from.classList.remove(a);
        if (from.getAttribute('class') === '') { from.removeAttribute('class'); }
        to.classList.add(a);
      }
    });
  }

  function hasAnyAlign(el) {
    return ALIGNS.some(function (a) { return a !== 'align-none' && el.classList.contains(a); });
  }

  /**
   * What the image bar's buttons do.
   *
   * ── THE CLASS GOES ON THE FIGURE WHEN THERE IS ONE ─────────────────────────
   * A captioned image is `<figure><img><figcaption></figcaption></figure>`, and
   * floating the `img` inside a figure floats it away from its own caption.
   * Whichever of the two is the outermost is the thing that moves.
   */
  function runImage(tool, surface, textarea, setSelectedImage) {
    var img = surface.querySelector('img.is-selected');
    if (!img) { return; }

    var box  = alignHost(img);
    var done = function () {
      textarea.value = htmlOut(surface);
    };

    if (tool.img === 'align') {
      ALIGNS.forEach(function (a) { box.classList.remove(a); });
      // `align-none` IS THE ABSENCE, not a class. Writing it out would mean the
      // theme had to style "no alignment", and every image that was never
      // aligned would differ from one that was explicitly set to none.
      if (tool.arg !== 'align-none') { box.classList.add(tool.arg); }
      if (box.getAttribute('class') === '') { box.removeAttribute('class'); }
      setSelectedImage(img);
      return;
    }

    if (tool.img === 'details') {
      // ── EVERYTHING ABOUT THE PICTURE, IN ONE FORM ────────────────────────
      // One dialog holding the lot, rather than two buttons opening two
      // one-field prompts. Alt and caption are written in the same breath, and
      // width and link need a control too.
      //
      // `prompt.js` grew a many-field shape to carry it rather than a second
      // dialog component being invented — see its header.
      var figure  = img.closest('figure');
      var capText = figure
        ? ((figure.querySelector('figcaption') || {}).textContent || '')
        : '';
      var anchor  = img.parentNode && img.parentNode.nodeName === 'A' ? img.parentNode : null;

      window.Prompt.ask({
        title: 'Image details',
        verb: 'Save',
        fields: [
          {
            name: 'alt',
            label: 'Description',
            value: img.getAttribute('alt') || '',
            placeholder: 'Two members at the front desk',
            help: 'For people who cannot see it, and for search results. '
                + 'Leave it blank if the image is decorative.'
          },
          {
            name: 'caption',
            label: 'Caption',
            value: capText,
            help: 'Shown under the image, on the page. Empty for none.'
          },
          {
            // A NUMBER OF PIXELS, AND NOT "Thumbnail / Medium / Large".
            // This install generates no derivatives at all —
            // `www/uploads/versions` is version history, not sizes. A menu
            // offering four sizes that all serve the same file is a control
            // that lies about what it does.
            name: 'width',
            label: 'Width',
            type: 'number',
            min: 40,
            value: img.getAttribute('width') || '',
            help: 'In pixels. Leave it blank for the picture\'s own size — or '
                + 'drag a corner of the image itself.'
          },
          {
            // ONE FIELD, NOT A MODE. A menu of None / Media file / Custom URL,
            // plus a box that appears for the third, says no more than an
            // address box with the file's own address as its placeholder —
            // which has no state to get wrong.
            name: 'href',
            label: 'Link to',
            value: anchor ? (anchor.getAttribute('href') || '') : '',
            placeholder: img.getAttribute('src') || '',
            help: 'Leave it blank for no link. Use the image\'s own address to '
                + 'open the full-size picture.'
          }
        ]
      }).then(function (v) {
        if (v === null) { return; }

        img.alt = v.alt;

        // ── WIDTH ────────────────────────────────────────────────────────
        var w = parseInt(v.width, 10);
        if (v.width === '' || isNaN(w) || w < 40) {
          img.removeAttribute('width');
          img.removeAttribute('height');
        } else if (String(w) !== img.getAttribute('width')) {
          // The HEIGHT GOES WITH IT, from the picture's own ratio, or the
          // browser reflows the page around it as the file loads.
          var ratio = img.naturalHeight && img.naturalWidth
            ? img.naturalHeight / img.naturalWidth : 0;
          img.setAttribute('width', String(w));
          if (ratio) { img.setAttribute('height', String(Math.round(w * ratio))); }
        }

        // ── LINK ─────────────────────────────────────────────────────────
        var href = (v.href || '').trim();
        if (href === '' && anchor) {
          anchor.parentNode.insertBefore(img, anchor);
          anchor.parentNode.removeChild(anchor);
        } else if (href !== '') {
          if (!anchor) {
            anchor = document.createElement('a');
            img.parentNode.insertBefore(anchor, img);
            anchor.appendChild(img);
          }
          anchor.setAttribute('href', href);
        }

        // ── CAPTION ──────────────────────────────────────────────────────
        // The FIGURE wraps whatever is outermost — the anchor when there is
        // one — or the caption would sit outside the link and the picture
        // inside it, which renders as two unrelated things.
        var outer = img.parentNode && img.parentNode.nodeName === 'A' ? img.parentNode : img;
        var text  = (v.caption || '').trim();

        if (text === '') {
          // AN EMPTY CAPTION REMOVES THE FIGURE rather than leaving an empty
          // one, which renders as a gap under the picture that nobody can see
          // to delete.
          if (figure) {
            figure.parentNode.insertBefore(outer, figure);
            moveAlign(figure, outer);
            figure.parentNode.removeChild(figure);
          }
        } else {
          if (!figure) {
            // WRAPPING MOVES THE ALIGNMENT OUT WITH IT. Left on the picture it
            // would float inside a figure that does not, and the caption would
            // sit alone in the text.
            //
            // FROM WHEREVER IT IS. The class may be on the `img` — put there
            // before any link existed — or on the anchor this dialog has just
            // made. Taking it only from `outer` left it on the picture, which
            // measured as a figure that did not float around a picture that
            // did.
            figure = document.createElement('figure');
            moveAlign(img, figure);
            if (outer !== img) { moveAlign(outer, figure); }
            outer.parentNode.insertBefore(figure, outer);
            figure.appendChild(outer);
          }
          var cap = figure.querySelector('figcaption');
          if (!cap) {
            cap = document.createElement('figcaption');
            figure.appendChild(cap);
          }
          cap.textContent = text;
        }

        unnestBlocks(surface);
        ensureBlock(surface);
        setSelectedImage(surface.querySelector('img.is-selected') || img);
        done();
      });
      return;
    }

    if (tool.img === 'replace') {
      if (!window.CmsMedia) { return; }
      window.CmsMedia.pick(function (chosen) {
        if (!chosen || !chosen.url) { return; }
        // THE ALT TEXT IS NOT CARRIED OVER. It described the OLD picture, and a
        // description that belongs to a different image is worse than none —
        // it is confidently wrong to the one reader who depends on it.
        img.src = chosen.url;
        img.alt = '';
        setSelectedImage(img);
        done();
      });
      return;
    }

    if (tool.img === 'remove') {
      // THE WHOLE THING GOES — the figure with its caption, or the anchor with
      // the picture in it. Removing only the `img` would leave an empty link
      // and an orphan caption that nobody can see to delete.
      var target = alignHost(img);
      target.parentNode.removeChild(target);
      setSelectedImage(null);
      ensureBlock(surface);
      done();
    }
  }

  /**
   * HTML FOR THE SURFACE: each embed from one of the five providers becomes a
   * block of its own showing the provider's own frame (`embedBlock()`); any
   * other frame goes, and so does a script, as they do on save. Parsed inertly
   * first (`DOMParser` loads and runs nothing), so no other frame ever loads in
   * the admin and no provider's script ever runs in it.
   */
  function surfaceHtml(html) {
    var doc = new DOMParser().parseFromString('<body>' + (html || '') + '</body>', 'text/html');
    // A block is the editor's own, never content: one that arrives from the
    // HTML view is only a box.
    Array.prototype.forEach.call(doc.querySelectorAll('.editor__embed, [data-code]'), function (el) {
      el.classList.remove('editor__embed');
      el.removeAttribute('data-code');
      el.removeAttribute('contenteditable');
    });
    var groups = embedGroups(doc.body);
    var kept = [];
    groups.forEach(function (group) { kept = kept.concat(group.nodes); });
    Array.prototype.slice.call(doc.querySelectorAll('iframe, script')).forEach(function (el) {
      if (kept.some(function (n) { return n === el || n.contains(el); })) { return; }
      var holder = el.parentNode;
      holder.removeChild(el);
      // A PARAGRAPH THAT HELD ONLY THAT goes with it: an empty line nobody
      // typed, which a save would keep.
      if (holder.nodeName === 'P' && !holder.children.length && !holder.textContent.trim()) {
        holder.parentNode.removeChild(holder);
      }
    });
    groups.forEach(function (group) {
      group.nodes[0].parentNode.insertBefore(embedBlock(group, doc), group.nodes[0]);
      group.nodes.forEach(function (n) { n.parentNode.removeChild(n); });
    });
    return doc.body.innerHTML;
  }

  // ── THE FIVE PROVIDERS (`Html\Embed` on the server says the same) ────────
  // Which frame, quote and script is whose, and what each provider's code
  // writes on its frame. The server is the guarantee; this decides what the
  // surface shows, and what the HTML view and the save see before it does.
  var EMBEDS = [
    {
      name: 'youtube',
      // https, YouTube's two embed hosts, a path under /embed/ — a video, a
      // `videoseries` playlist or a `live_stream`.
      frame: /^https:\/\/(?:www\.youtube\.com|www\.youtube-nocookie\.com)\/embed\/[A-Za-z0-9_-]+\/?(?:[?#][^\s"'<>\\]*)?$/,
      attrs: ['width', 'height', 'src', 'title', 'frameborder', 'allow', 'referrerpolicy', 'allowfullscreen'],
      scripts: []
    },
    {
      name: 'vimeo',
      // A video on Vimeo's player host, or a showcase or a live event; the
      // frame fills Vimeo's box, whose padding keeps the video's shape.
      frame: /^https:\/\/(?:player\.vimeo\.com\/video\/\d+|vimeo\.com\/(?:showcase\/\d+\/embed|event\/\d+\/embed(?:\/[A-Za-z0-9]+)?))\/?(?:[?#][^\s"'<>\\]*)?$/,
      attrs: ['src', 'width', 'height', 'frameborder', 'allow', 'referrerpolicy', 'style', 'title',
              'allowfullscreen', 'webkitallowfullscreen', 'mozallowfullscreen'],
      box: /^padding:\d{1,3}(?:\.\d{1,4})?% 0 0 0;position:relative$/,
      fill: 'position:absolute;top:0;left:0;width:100%;height:100%',
      scripts: ['https://player.vimeo.com/api/player.js']
    },
    {
      name: 'facebook',
      // A post, a video, a page or a comment, from Facebook's plugin address.
      frame: /^https:\/\/www\.facebook\.com\/plugins\/(?:post|video|page|comment_embed)\.php(?:[?#][^\s"'<>\\]*)?$/,
      attrs: ['src', 'width', 'height', 'style', 'scrolling', 'frameborder', 'allowfullscreen', 'allow',
              'allowtransparency', 'title'],
      style: 'border:none;overflow:hidden',
      scripts: []
    },
    {
      name: 'instagram',
      // Instagram's blockquote, naming a post, a reel or a video. What shows is
      // Instagram's own frame for that post — the one its script would put in
      // the blockquote's place — and it says how tall it is.
      permalink: function (quote) {
        return quote.classList.contains('instagram-media')
          ? String(quote.getAttribute('data-instgrm-permalink') || '').trim() : '';
      },
      post: /^https:\/\/www\.instagram\.com\/(?:[A-Za-z0-9_.]+\/)?(p|reel|tv)\/([A-Za-z0-9_-]+)\/?(?:[?#][^\s"'<>\\]*)?$/,
      scripts: ['//www.instagram.com/embed.js', 'https://www.instagram.com/embed.js'],
      show: function (quote, post) {
        return 'https://www.instagram.com/' + post[1] + '/' + post[2] + '/embed/'
          + (quote.hasAttribute('data-instgrm-captioned') ? 'captioned/' : '');
      },
      origin: 'https://www.instagram.com',
      size: [540, 600],
      height: function (data) {
        var d = data;
        if (typeof d === 'string') { try { d = JSON.parse(d); } catch (err) { return 0; } }
        return d && d.type === 'MEASURE' && d.details ? Number(d.details.height) || 0 : 0;
      }
    },
    {
      name: 'x',
      // X's blockquote, which names a post by the link to it, on x.com or on an
      // older code's twitter.com (`Html\XPost`). What shows is X's own frame
      // for that post, in the code's colours or else the admin's, and it says
      // how tall it is.
      permalink: function (quote) {
        var classes = String(quote.getAttribute('class') || '').trim().split(/\s+/);
        var isX = classes.some(function (c) { return c === 'twitter-tweet' || c === 'twitter-video'; })
          && classes.every(function (c) { return /^(?:twitter-tweet|twitter-video|tw-align-(?:left|right|center))$/.test(c); });
        var links = isX ? quote.querySelectorAll('a[href]') : [];
        for (var i = 0; i < links.length; i++) {
          var href = String(links[i].getAttribute('href')).trim();
          if (this.post.test(href)) { return href; }
        }
        return '';
      },
      post: /^(?:https:\/\/(?:www\.|mobile\.)?x\.com|https?:\/\/(?:www\.)?twitter\.com|https:\/\/mobile\.twitter\.com)\/(?:[A-Za-z0-9_]{1,20}|i(?:\/web)?)\/status(?:es)?\/(\d{1,25})\/?(?:[?#][^\s"'<>\\]*)?$/,
      scripts: ['https://platform.x.com/widgets.js', 'https://platform.twitter.com/widgets.js',
                '//platform.x.com/widgets.js', '//platform.twitter.com/widgets.js'],
      // X's code writes it on its script, beside the address.
      charset: 'utf-8',
      show: function (quote, post) {
        var theme = quote.getAttribute('data-theme');
        if (theme !== 'light' && theme !== 'dark') {
          theme = /dark/.test(window.getComputedStyle(document.documentElement).colorScheme) ? 'dark' : 'light';
        }
        return 'https://platform.twitter.com/embed/Tweet.html?id=' + post[1] + '&theme=' + theme + '&dnt=true';
      },
      origin: 'https://platform.twitter.com',
      size: [550, 400],
      height: function (data) {
        var d = data;
        if (typeof d === 'string') { try { d = JSON.parse(d); } catch (err) { return 0; } }
        var call = d && d['twttr.embed'];
        var size = call && call.method === 'twttr.private.resize' && call.params && call.params[0];
        return size ? Number(size.height) || 0 : 0;
      }
    }
  ];

  /** A `style` as a provider writes it, compared without its spacing, case or last semicolon (`Embed::style()`). */
  function styleOf(value) {
    return String(value || '').toLowerCase().trim().replace(/\s+/g, ' ')
      .replace(/\s*([:;,])\s*/g, '$1').replace(/;+$/, '');
  }

  function frameProvider(src) {
    src = String(src || '').trim();
    for (var i = 0; i < EMBEDS.length; i++) {
      if (EMBEDS[i].frame && EMBEDS[i].frame.test(src)) { return EMBEDS[i]; }
    }
    return null;
  }

  /** Whose box is this element: the provider's style, and the provider's frame alone inside it. */
  function boxProvider(el) {
    if (!el || el.nodeName !== 'DIV') { return null; }
    var frames = Array.prototype.filter.call(el.childNodes, function (n) {
      return !(n.nodeType === 3 && !n.textContent.trim());
    });
    if (frames.length !== 1 || frames[0].nodeName !== 'IFRAME') { return null; }
    var p = frameProvider(frames[0].getAttribute('src'));
    return p && p.box && p.box.test(styleOf(el.getAttribute('style'))) ? p : null;
  }

  function quoteProvider(el) {
    for (var i = 0; i < EMBEDS.length; i++) {
      var p = EMBEDS[i];
      if (p.permalink && p.post.test(p.permalink(el))) { return p; }
    }
    return null;
  }

  function scriptProvider(el) {
    var src = String(el.getAttribute('src') || '').trim();
    for (var i = 0; i < EMBEDS.length; i++) {
      if (EMBEDS[i].scripts.indexOf(src) !== -1) { return EMBEDS[i]; }
    }
    return null;
  }

  /**
   * THE EMBEDS IN A PIECE OF HTML, in order: each one's own nodes — the
   * provider's box or frame, or its quote — and the provider's script after
   * it, with only white space between.
   */
  function embedGroups(root) {
    var groups = [];
    var taken = [];
    Array.prototype.forEach.call(root.querySelectorAll('iframe, blockquote'), function (el) {
      if (taken.some(function (n) { return n.contains(el); })) { return; }
      var p, first;
      if (el.nodeName === 'IFRAME') {
        p = frameProvider(el.getAttribute('src'));
        first = p && boxProvider(el.parentNode) === p ? el.parentNode : el;
      } else {
        p = quoteProvider(el);
        first = el;
      }
      if (!p) { return; }
      var nodes = [first];
      var gap = [];
      var next = first.nextSibling;
      while (next && next.nodeType === 3 && !next.textContent.trim()) { gap.push(next); next = next.nextSibling; }
      if (next && next.nodeName === 'SCRIPT' && scriptProvider(next) === p) { nodes = nodes.concat(gap, [next]); }
      groups.push({ provider: p, nodes: nodes });
      taken = taken.concat(nodes);
    });
    return groups;
  }

  /**
   * A COPY OF ONE OF AN EMBED'S NODES, AS THE SAVE WILL KEEP IT: a frame with
   * only the attributes its provider writes (a size only if it is a number, a
   * style only if it is the provider's own), the box with its style alone,
   * the script with its address and nothing inside it. The quote goes as
   * pasted; the server decides each of its parts.
   */
  function embedNode(node, p) {
    var copy = node.cloneNode(true);
    if (node.nodeType !== 1 || copy.nodeName === 'BLOCKQUOTE') { return copy; }
    var keep = function (el, test) {
      Array.prototype.slice.call(el.attributes).forEach(function (a) {
        if (!test(a.name.toLowerCase(), a.value.trim())) { el.removeAttribute(a.name); }
      });
    };
    var frame = copy.nodeName === 'IFRAME' ? copy : copy.querySelector('iframe');
    if (copy.nodeName === 'SCRIPT') {
      keep(copy, function (name, value) {
        return name === 'src' || ((name === 'async' || name === 'defer') && (value === '' || value === name))
          || (name === 'charset' && value.toLowerCase() === 'utf-8');
      });
      copy.textContent = '';
      return copy;
    }
    if (copy !== frame) { keep(copy, function (name) { return name === 'style'; }); }
    keep(frame, function (name, value) {
      if (p.attrs.indexOf(name) === -1) { return false; }
      if (name === 'width' || name === 'height' || name === 'frameborder') { return /^\d{1,4}%?$/.test(value); }
      if (name === 'scrolling') { return /^(no|yes|auto)$/i.test(value); }
      if (name === 'style') { return styleOf(value) === p.style || (copy !== frame && styleOf(value) === p.fill); }
      return true;
    });
    frame.textContent = '';
    return copy;
  }

  /**
   * AN EMBED AS A BLOCK OF THE SURFACE. It shows the provider's own frame —
   * the frame the code draws, or for a quote the frame the provider's script
   * would draw — and keeps the code itself (`data-code`), which is what
   * `htmlOut()` writes in its place. It is not text to type into: the caret
   * goes before or after it, and Backspace or Delete beside it selects it, then
   * takes it all.
   */
  function embedBlock(group, doc) {
    var p = group.provider;
    var holder = doc.createElement('div');
    group.nodes.forEach(function (n) { holder.appendChild(embedNode(n, p)); });
    var show;
    if (p.permalink) {
      var quote = group.nodes[0];
      show = doc.createElement('iframe');
      show.setAttribute('src', p.show(quote, p.post.exec(p.permalink(quote))));
      show.setAttribute('width', String(p.size[0]));
      show.setAttribute('height', String(p.size[1]));
      show.setAttribute('frameborder', '0');
      show.setAttribute('scrolling', 'no');
      show.setAttribute('title', quote.textContent.trim().slice(0, 120));
    } else {
      show = embedNode(group.nodes[0], p);
    }
    var block = doc.createElement('div');
    block.className = 'editor__embed';
    block.setAttribute('contenteditable', 'false');
    block.setAttribute('data-embed', p.name);
    block.setAttribute('data-code', holder.innerHTML);
    if (p.permalink) {
      block.classList.add('editor__embed--quote');
      block.style.maxWidth = p.size[0] + 'px';
      block.appendChild(quoteWords(group.nodes[0], doc));
    }
    block.appendChild(show);
    return block;
  }

  /**
   * A quote's words, as its blockquote has them: paragraphs, line breaks and
   * text, and nothing else — no link to follow out of the editor, and nothing
   * of a pasted code that could run. They show until the provider's frame says
   * how tall it is, and for good if the provider is blocked.
   */
  function quoteWords(quote, doc) {
    var copy = function (from, to) {
      Array.prototype.forEach.call(from.childNodes, function (n) {
        if (n.nodeType === 3) {
          to.appendChild(doc.createTextNode(n.textContent));
        } else if (n.nodeName === 'P' || n.nodeName === 'BR') {
          copy(n, to.appendChild(doc.createElement(n.nodeName.toLowerCase())));
        } else if (n.nodeType === 1 && n.nodeName !== 'SCRIPT' && n.nodeName !== 'STYLE') {
          copy(n, to);
        }
      });
      return to;
    };
    return copy(quote, doc.createElement('blockquote'));
  }

  /** The embed a pasted text is — exactly one, with its script, and nothing else — as a block, or null. */
  function pastedEmbed(text) {
    var doc = new DOMParser().parseFromString('<body>' + (text || '') + '</body>', 'text/html');
    var groups = embedGroups(doc.body);
    var nodes = Array.prototype.filter.call(doc.body.childNodes, function (n) {
      return !(n.nodeType === 3 && !n.textContent.trim());
    });
    if (groups.length !== 1 || !nodes.every(function (n) { return groups[0].nodes.indexOf(n) !== -1; })) {
      return null;
    }
    var group = groups[0];
    // A QUOTE COPIED WITHOUT ITS SCRIPT gets the provider's, as its own code
    // writes it: without one the page shows the words and never the post.
    if (group.provider.permalink && group.nodes.length === 1) {
      var script = doc.createElement('script');
      script.setAttribute('async', '');
      script.setAttribute('src', group.provider.scripts[0]);
      if (group.provider.charset) { script.setAttribute('charset', group.provider.charset); }
      group.nodes.push(doc.createTextNode('\n'), script);
    }
    return embedBlock(group, document);
  }

  // ── A QUOTE'S FRAME SAYS HOW TALL IT IS ──────────────────────────────────
  // The provider's own frame posts its height to the page that holds it. Only
  // from the provider's own address, only to the frame it came from, and
  // never taller than a page.
  window.addEventListener('message', function (e) {
    EMBEDS.forEach(function (p) {
      if (!p.origin || e.origin !== p.origin) { return; }
      var height = Math.min(Math.ceil(p.height(e.data)), 4000);
      if (!(height > 0)) { return; }
      Array.prototype.forEach.call(document.querySelectorAll('.editor__embed[data-embed="' + p.name + '"] iframe'), function (f) {
        if (f.contentWindow === e.source) {
          f.setAttribute('height', String(height));
          f.parentNode.classList.add('is-drawn');
        }
      });
    });
  });

  /**
   * A BLOCK OF ITS OWN at the caret, or several in a fragment: the block the
   * caret is in is split there, the new ones go between the halves, an empty
   * half goes, and the caret waits on the line after them.
   */
  function insertBlock(surface, node) {
    var last = node.nodeType === 11 ? node.lastChild : node;
    if (!last) { return; }
    var sel = window.getSelection();
    var range = sel && sel.rangeCount ? sel.getRangeAt(0) : null;
    var filled = function (el) { return el.textContent.trim() !== '' || !!el.querySelector('img, iframe, hr'); };
    if (!range || !surface.contains(range.startContainer)) {
      surface.appendChild(node);
    } else {
      range.deleteContents();
      var block = range.startContainer;
      while (block && block !== surface && block.parentNode !== surface) { block = block.parentNode; }
      if (!block || block === surface) {
        range.insertNode(node);
      } else {
        var tail = document.createRange();
        tail.setStart(range.startContainer, range.startOffset);
        tail.setEnd(block, block.childNodes.length);
        var rest = block.cloneNode(false);
        rest.appendChild(tail.extractContents());
        surface.insertBefore(node, block.nextSibling);
        if (filled(rest)) { surface.insertBefore(rest, last.nextSibling); }
        if (!filled(block)) { surface.removeChild(block); }
      }
    }
    var next = last.nextSibling;
    if (!next) {
      next = document.createElement('p');
      next.appendChild(document.createElement('br'));
      surface.appendChild(next);
    }
    if (sel) {
      var at = document.createRange();
      at.setStart(next, 0);
      at.collapse(true);
      sel.removeAllRanges();
      sel.addRange(at);
    }
  }

  function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function boot() {
    document.querySelectorAll('textarea[data-editor]').forEach(build);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
