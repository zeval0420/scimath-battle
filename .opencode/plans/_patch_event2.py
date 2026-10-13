f = r"C:\xampp\htdocs\reserve-a-lab\scimath-battle\public\operator\event.php"
with open(f, "r", encoding="utf-8") as fh:
    content = fh.read()

# 1. Replace the end of the root element to include data-last-scored-question-id
old_attr = "     data-questions='<?= htmlspecialchars(json_encode($questionListPayload), ENT_QUOTES) ?>'>"
new_attr = "     data-questions='<?= htmlspecialchars(json_encode($questionListPayload), ENT_QUOTES) ?>'\n     data-last-scored-question-id='<?= $dashboard[\"last_scored_question_id\"] ?? \"null\" ?>'>"

if old_attr in content:
    content = content.replace(old_attr, new_attr)
    print("1. Added data-last-scored-question-id")
else:
    print("1. ERROR: old_attr not found")

# 2. Add action buttons
old_toggle = '                <div class="op-toggle-row">\n                    <span class="op-toggle-title">Show question on display</span>'
new_buttons = '''                <div class="op-action-buttons">
                    <button type="button" class="btn btn-primary op-action-btn" id="op-btn-show-question" disabled>Show Question</button>
                    <button type="button" class="btn btn-primary op-action-btn" id="op-btn-show-answer" disabled>Show Answer</button>
                    <button type="button" class="btn btn-success op-action-btn" id="op-btn-show-results" disabled>Show Results</button>
                </div>

                <div class="op-toggle-row">
                    <span class="op-toggle-title">Show question on display</span>'''

if old_toggle in content:
    content = content.replace(old_toggle, new_buttons)
    print("2. Added action buttons")
else:
    print("2. ERROR: old_toggle not found")

# 3. Add results panel
old_footer = '    </div>\n\n    <p class="op-poll-indicator" id="op-poll-indicator">Live — updating every second</p>'
new_results = '''        <div class="op-panel op-panel--wide" data-op-component="results" id="op-results-panel" style="display:none;">
            <h2>Question Results &mdash; <span id="op-results-round-label">—</span></h2>
            <div class="op-results-info">
                <span>Most recently scored: <strong id="op-results-last-q">—</strong></span>
            </div>
            <div class="op-results-matrix-wrap" id="op-results-matrix-wrap">
                <p class="muted">Click &ldquo;Show Results&rdquo; to display the round breakdown.</p>
            </div>
            <div class="op-nav-buttons" style="margin-top:14px;">
                <button type="button" class="btn" id="op-btn-close-results">Close</button>
                <select id="op-results-round-select" class="btn">
                    <?php foreach (Round::ALL as $r): ?>
                        <option value="<?= $r ?>"><?= htmlspecialchars(Round::label($r)) ?> Round</option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-primary" id="op-btn-refresh-results">Refresh</button>
            </div>
        </div>
    </div>

    <p class="op-poll-indicator" id="op-poll-indicator">Live — updating every second</p>'''

if old_footer in content:
    content = content.replace(old_footer, new_results)
    print("3. Added results panel")
else:
    print("3. ERROR: old_footer not found")

with open(f, "w", encoding="utf-8") as fh:
    fh.write(content)
print("event.php updated")
