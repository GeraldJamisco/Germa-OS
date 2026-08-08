/* ==========================================================================
   GERMA OS dashboard behaviour
   No framework. Every mutation goes through the JSON API and reports back;
   nothing is faked optimistically except the priority checkbox, which is
   reverted if the server disagrees.
   ========================================================================== */
(() => {
  "use strict";

  const body = document.body;
  const BASE = body.dataset.base || "/";
  const TOKEN = body.dataset.token || "";

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const url = (path) => BASE.replace(/\/$/, "") + "/" + path.replace(/^\//, "");

  async function post(path, payload = {}) {
    const response = await fetch(url(path), {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": TOKEN },
      body: JSON.stringify(payload),
    });

    let data;
    try {
      data = await response.json();
    } catch {
      throw new Error(
        "The server returned something that was not JSON (HTTP " +
          response.status +
          ").",
      );
    }

    if (!response.ok || data.ok === false) {
      throw new Error(
        data.error || "Request failed (HTTP " + response.status + ").",
      );
    }
    return data;
  }

  /* --- Toasts ------------------------------------------------------------ */
  function toast(message, isError = false) {
    const host = $("[data-toasts]");
    if (!host) return;

    const el = document.createElement("div");
    el.className = "toast" + (isError ? " toast--error" : "");
    el.textContent = message;
    host.appendChild(el);

    setTimeout(() => el.remove(), isError ? 7000 : 4000);
  }

  /* --- Sidebar drawer ---------------------------------------------------- */
  const sidebar = $("#sidebar");
  let scrim = null;

  function closeDrawer() {
    if (!sidebar) return;
    sidebar.dataset.open = "0";
    scrim?.remove();
    scrim = null;
  }

  $("[data-menu-toggle]")?.addEventListener("click", () => {
    if (!sidebar) return;

    if (sidebar.dataset.open === "1") {
      closeDrawer();
      return;
    }

    sidebar.dataset.open = "1";
    scrim = document.createElement("div");
    scrim.className = "scrim";
    scrim.addEventListener("click", closeDrawer);
    body.appendChild(scrim);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") closeDrawer();
  });

  /* --- Score explanation ------------------------------------------------- */
  $("[data-explain-score]")?.addEventListener("click", (event) => {
    const panel = $("[data-score-explanation]");
    if (!panel) return;
    panel.hidden = !panel.hidden;
    event.currentTarget.textContent = panel.hidden
      ? "Why this score? →"
      : "Hide breakdown";
  });

  $$("[data-scroll-to]").forEach((button) => {
    button.addEventListener("click", () => {
      document
        .getElementById(button.dataset.scrollTo)
        ?.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  });

  /* --- Notifications ------------------------------------------------------
     The bell opens the alerts it counts. Closes on outside click and on Escape,
     because a panel you cannot dismiss is worse than no panel.               */
  const notifToggle = $("[data-notif-toggle]");
  const notifPanel = $("#germa-notifications");

  function setNotifOpen(open) {
    if (!notifToggle || !notifPanel) return;
    notifPanel.hidden = !open;
    notifToggle.setAttribute("aria-expanded", open ? "true" : "false");
  }

  notifToggle?.addEventListener("click", (event) => {
    event.stopPropagation();
    setNotifOpen(notifPanel.hidden);
  });

  notifPanel?.addEventListener("click", (event) => event.stopPropagation());

  document.addEventListener("click", () => setNotifOpen(false));
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && notifPanel && !notifPanel.hidden) {
      setNotifOpen(false);
      notifToggle?.focus();
    }
  });

  /* --- Priority toggle --------------------------------------------------- */
  $$("[data-toggle-task]").forEach((button) => {
    button.addEventListener("click", async () => {
      const row = button.closest(".priority");
      const was = row.dataset.done === "1";
      const next = !was;

      // Optimistic: the checkbox has to feel instant.
      row.dataset.done = next ? "1" : "0";
      button.textContent = next ? "✓" : "";
      button.setAttribute("aria-pressed", String(next));

      try {
        const data = await post(
          "api/tasks/" + button.dataset.toggleTask + "/toggle",
        );
        if (data.score) repaintScore(data.score);
      } catch (error) {
        row.dataset.done = was ? "1" : "0";
        button.textContent = was ? "✓" : "";
        button.setAttribute("aria-pressed", String(was));
        toast(error.message, true);
      }
    });
  });

  function repaintScore(score) {
    const total = $("[data-score-total]");
    if (total) total.textContent = score.total;

    const parts = $("[data-score-parts]");
    if (!parts || !Array.isArray(score.parts)) return;

    score.parts.forEach((part, index) => {
      const meter = parts.children[index];
      if (!meter) return;
      meter.querySelector(".meter__value").textContent = part.value;
      meter.querySelector(".bar__fill").style.width =
        Math.max(0, Math.min(100, part.value)) + "%";
    });
  }

  /* --- Alerts ------------------------------------------------------------ */
  $$("[data-dismiss-alert]").forEach((button) => {
    button.addEventListener("click", async () => {
      const card = button.closest(".alert");
      card.classList.add("is-busy");

      try {
        await post("api/alerts/" + button.dataset.dismissAlert + "/dismiss");
        card.remove();
        toast(
          "Alert dismissed. It returns if the underlying problem persists.",
        );
      } catch (error) {
        card.classList.remove("is-busy");
        toast(error.message, true);
      }
    });
  });

  /* --- End-of-day review -------------------------------------------------- */
  const reviewForm = $("[data-review-form]");

  reviewForm?.addEventListener("submit", async (event) => {
    event.preventDefault();

    const submit = $('button[type="submit"]', reviewForm);
    const box = $("[data-review-result]");
    const body = $("[data-review-body]");

    submit.disabled = true;
    const original = submit.textContent;
    submit.textContent = "Scoring…";

    try {
      const values = Object.fromEntries(new FormData(reviewForm).entries());
      const data = await post("api/entries/day-review", values);

      // The review's whole point is the verdict, so show it in place rather
      // than reloading and losing it.
      const nodes = [document.createElement("p")];
      nodes[0].textContent = data.message;
      nodes[0].style.fontWeight = "600";
      nodes[0].style.color = "var(--ink)";

      if (Array.isArray(data.explanation)) {
        const list = document.createElement("ul");
        list.className = "t-note";
        list.style.margin = "8px 0 0";
        list.style.paddingLeft = "18px";
        data.explanation.forEach((line) => {
          const li = document.createElement("li");
          li.textContent = line;
          list.append(li);
        });
        nodes.push(list);
      }

      body.replaceChildren(...nodes);
      box.hidden = false;
      box.scrollIntoView({ behavior: "smooth", block: "nearest" });
      toast("Day closed.");
    } catch (error) {
      toast(error.message, true);
    } finally {
      submit.disabled = false;
      submit.textContent = original;
    }
  });

  $("[data-close-review]")?.addEventListener("click", () => {
    const box = $("[data-review-result]");
    if (box) box.hidden = true;
  });

  /* --- Bills ------------------------------------------------------------- */
  $$("[data-pay-bill]").forEach((button) => {
    button.addEventListener("click", async () => {
      const row = button.closest(".duerow");
      row.classList.add("is-busy");

      try {
        const data = await post("api/entries/pay-bill", {
          bill_id: button.dataset.payBill,
        });
        toast(data.message || "Bill paid.");
        // The bill leaves the list, the ledger gains a row, and the runway
        // moves: too much to patch in place.
        setTimeout(() => window.location.reload(), 600);
      } catch (error) {
        row.classList.remove("is-busy");
        toast(error.message, true);
      }
    });
  });

  /* --- Command dock ------------------------------------------------------ */
  const form = $("[data-command-form]");
  const input = form ? $(".dock__input", form) : null;
  const answerBox = $("[data-answer]");
  const answerBody = $("[data-answer-body]");

  /**
   * Renders the small subset of markdown the model is asked to use: blank-line
   * paragraphs, "- " bullets, and **bold** runs. Built with DOM nodes rather
   * than innerHTML so model output can never inject markup.
   */
  function renderAnswer(text) {
    const nodes = [];
    let list = null;

    for (const block of String(text).split(/\n{2,}/)) {
      for (const line of block.split("\n")) {
        const trimmed = line.trim();
        if (trimmed === "") continue;

        const bullet = /^[-*•]\s+/.test(trimmed);

        if (bullet) {
          if (!list) {
            list = document.createElement("ul");
            list.style.margin = "0 0 .7em";
            list.style.paddingLeft = "18px";
            nodes.push(list);
          }
          const li = document.createElement("li");
          li.style.fontSize = "14px";
          li.style.lineHeight = "1.55";
          li.append(...inline(trimmed.replace(/^[-*•]\s+/, "")));
          list.append(li);
          continue;
        }

        list = null;
        const p = document.createElement("p");
        p.append(...inline(trimmed.replace(/^#{1,6}\s+/, "")));
        nodes.push(p);
      }
      list = null;
    }

    return nodes.length ? nodes : [document.createTextNode(String(text))];
  }

  function inline(text) {
    return text
      .split(/(\*\*[^*]+\*\*)/g)
      .filter(Boolean)
      .map((part) => {
        if (part.startsWith("**") && part.endsWith("**")) {
          const strong = document.createElement("strong");
          strong.style.color = "var(--ink)";
          strong.textContent = part.slice(2, -2);
          return strong;
        }
        return document.createTextNode(part);
      });
  }

  function showAnswer(text, generator) {
    if (!answerBox || !answerBody) return;

    answerBody.replaceChildren(...renderAnswer(text));

    // The chip must report what produced this answer, not what is configured.
    if (generator) {
      const chip = $(".chip", answerBox);
      if (chip) {
        const live = generator === "claude";
        chip.className = "chip " + (live ? "chip--ai" : "chip--calculated");
        chip.textContent = live ? "AI" : "Rules";
      }
    }

    answerBox.hidden = false;
    answerBox.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  $("[data-close-answer]")?.addEventListener("click", () => {
    if (answerBox) answerBox.hidden = true;
  });

  // The boardroom renders the answer inside its own card: the dock's overlay
  // would cover the composer you just typed into.
  function renderInto(host, text, generator) {
    const box = $("[data-board-answer-text]", host) || host;
    box.textContent = text;
    host.hidden = false;
    const chip = $(".chip", host);
    if (chip && generator) {
      chip.className = "chip " + (generator === "claude" ? "chip--ai" : "chip--calculated");
      chip.textContent = generator === "claude" ? "The board" : "Rules engine";
    }
    host.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  async function ask(question, options = {}) {
    if (!question.trim()) return;

    const host = options.target || null;
    const submit = options.submit || (form ? $('button[type="submit"]', form) : null);
    const restore = submit ? submit.textContent : null;
    if (submit) {
      submit.disabled = true;
      submit.textContent = "Thinking…";
    }
    if (host) renderInto(host, "…");
    else showAnswer("…");

    try {
      const data = await post("api/command", { question });
      if (host) renderInto(host, data.answer, data.generator);
      else showAnswer(data.answer, data.generator);
      if (data.generator === "rules") {
        toast(
          "Answered from your recorded figures the live model was unavailable.",
          true,
        );
      }
    } catch (error) {
      if (host) renderInto(host, error.message);
      else showAnswer(error.message);
      toast(error.message, true);
    } finally {
      if (submit) {
        submit.disabled = false;
        submit.textContent = restore ?? "Send";
      }
    }
  }

  form?.addEventListener("submit", (event) => {
    event.preventDefault();
    const question = input.value;
    input.value = "";
    ask(question);
  });

  $$("[data-suggestion]").forEach((button) => {
    button.addEventListener("click", () => {
      if (input) input.value = button.dataset.suggestion;
      input?.focus();
    });
  });

  const boardAnswer = $("[data-board-answer]");

  $$("[data-ask]").forEach((button) => {
    button.addEventListener("click", () =>
      ask(button.dataset.ask, { target: boardAnswer }),
    );
  });

  $$("[data-focus]").forEach((button) => {
    button.addEventListener("click", () => {
      const target = $(button.dataset.focus);
      if (!target) return;
      target.scrollIntoView({ behavior: "smooth", block: "center" });
      target.focus();
    });
  });

  $("[data-board-ask]")?.addEventListener("submit", (event) => {
    event.preventDefault();
    const field = $("textarea", event.currentTarget);
    const question = field.value;
    ask(question, {
      target: boardAnswer,
      submit: $('button[type="submit"]', event.currentTarget),
    });
  });

  /* --- AI generation ------------------------------------------------------
     Model calls never block the page render. On the first load of the day the
     dashboard paints with rules output, then upgrades itself here.           */
  async function generateAi({ silent = false } = {}) {
    const button = $("[data-refresh-ai]") || $("[data-ai-refresh]");
    const stamp = $("[data-brief-stamp]");
    const refreshLabel = button ? button.textContent : null;

    if (button) {
      button.disabled = true;
      button.textContent = "Asking the board…";
    }
    if (stamp && !silent) stamp.textContent = "Asking the board…";

    try {
      const data = await post("api/ai/refresh");

      // Every hook below is optional: this runs on any screen that declares
      // data-ai-pending, not just the dashboard it was written for.
      const heroBrief = $(".hero__brief p");
      if (heroBrief) heroBrief.textContent = data.brief.brief;

      const recommendation = $("[data-board-recommendation]");
      if (recommendation)
        recommendation.textContent = data.board.recommendation;

      const rationale = $("[data-board-rationale]");
      if (rationale && data.board.rationale)
        rationale.textContent = data.board.rationale;

      repaintSignals(data.board.signals);

      const live = data.brief.generator === "claude";

      // The boardroom groups advisors by whether they object, and prints counts
      // and a roster note derived from that grouping. Repainting only the tiles
      // would leave the headings contradicting them, so it reloads instead.
      if (body.dataset.boardroom === "1") {
        location.reload();
        return;
      }

      const chip = $(".hero__brief .chip");
      if (chip) {
        chip.className = "chip " + (live ? "chip--ai" : "chip--calculated");
        chip.textContent = (live ? "AI" : "Rules") + " brief";
      }
      if (stamp) stamp.textContent = "Generated " + data.brief.generated_at;

      if (!silent || !live) {
        toast(
          live
            ? "Brief regenerated."
            : "Using rules output the model was unavailable.",
          !live,
        );
      }
    } catch (error) {
      if (stamp) stamp.textContent = "Model unavailable showing rules output";
      toast(error.message, true);
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = refreshLabel;
      }
    }
  }

  /* --- AI connection test -------------------------------------------------
     One live call, reported as it happened. This is the check that turns three
     configuration settings into a fact.                                      */
  $("[data-ai-test]")?.addEventListener("click", async (event) => {
    const button = event.currentTarget;
    const status = $("[data-ai-test-status]");
    const box = $("[data-ai-test-result]");
    const text = $("[data-ai-test-text]");
    const original = button.textContent;

    button.disabled = true;
    button.textContent = "Calling…";
    if (status) status.textContent = "Waiting on the model. This is not cached.";

    try {
      const data = await post("api/ai/test");
      const detail = data.reached ? data.answer : data.error;

      if (text) text.textContent = data.message + (detail ? " Returned: " + detail : "");
      if (box) {
        box.hidden = false;
        const chip = $(".chip", box);
        if (chip) {
          chip.className = "chip " + (data.reached ? "chip--ai" : "chip--unavailable");
          chip.textContent = data.reached ? "Reached" : "Failed";
        }
      }
      if (status) {
        status.textContent = data.reached
          ? data.model + " answered in " + data.latency + "ms"
          : "The call failed. The reason is above.";
      }
      toast(data.message, !data.reached);
    } catch (error) {
      if (text) text.textContent = error.message;
      if (box) box.hidden = false;
      toast(error.message, true);
    } finally {
      button.disabled = false;
      button.textContent = original;
    }
  });

  $("[data-refresh-ai]")?.addEventListener("click", () => generateAi());
  $("[data-ai-refresh]")?.addEventListener("click", () => generateAi());

  if (body.dataset.aiPending === "1") {
    generateAi({ silent: true });
  }

  function repaintSignals(signals) {
    const host = $("[data-board-signals]");
    if (!host || !Array.isArray(signals)) return;

    host.replaceChildren(
      ...signals.map((signal) => {
        const cell = document.createElement("div");
        cell.className = "signal";
        if (signal.note) cell.title = signal.note;

        const role = document.createElement("span");
        role.className = "signal__role";
        role.textContent = signal.role;

        const glyph = document.createElement("span");
        glyph.className = "signal__glyph";
        glyph.style.color = signal.colour;
        glyph.textContent = signal.glyph;

        cell.append(role, glyph);

        if (signal.note) {
          const note = document.createElement("span");
          note.className = "signal__note";
          note.textContent = signal.note;
          cell.append(note);
        }

        return cell;
      }),
    );
  }

  /* --- Quick add --------------------------------------------------------- */
  const today = new Date().toISOString().slice(0, 10);

  // Reference lists come from the shell, so any form can offer a picker
  // without fetching.
  const embeddedList = (selector) => {
    try {
      return JSON.parse(document.querySelector(selector)?.textContent || "[]");
    } catch {
      return [];
    }
  };
  const PROJECTS = embeddedList("[data-germa-projects]");
  const SAVINGS_GOALS = embeddedList("[data-germa-savings-goals]");
  const DECISIONS = embeddedList("[data-germa-decisions]");
  const GOALS = embeddedList("[data-germa-goals]");
  const MILESTONES = embeddedList("[data-germa-milestones]");
  const COMMITMENTS = embeddedList("[data-germa-commitments]");

  const pickerOptions = (rows, emptyLabel) =>
    [["", emptyLabel]].concat(rows.map((r) => [String(r.id), r.name]));

  const projectOptions = (required) =>
    pickerOptions(
      PROJECTS,
      required ? ",  choose a project , " : ",  not linked to a project , ",
    );
  const decisionOptions = () =>
    pickerOptions(DECISIONS, "— choose a decision —");
  const goalOptions = () => pickerOptions(GOALS, "— choose a goal —");
  const milestoneOptions = () =>
    pickerOptions(MILESTONES, "— choose a milestone —");
  const commitmentOptions = () =>
    pickerOptions(COMMITMENTS, "— choose a commitment —");
  const savingsGoalOptions = () =>
    pickerOptions(SAVINGS_GOALS, ",  general savings , ");

  const FORMS = {
    income: {
      title: "Add income",
      hint: "Money that has actually arrived. This moves your cash balance and today's target.",
      submit: "Record income",
      fields: [
        {
          name: "amount",
          label: "Amount",
          type: "number",
          required: true,
          placeholder: "150000",
          step: "any",
          autofocus: true,
        },
        {
          name: "description",
          label: "What was it for?",
          type: "text",
          placeholder: "Client Websites milestone 2",
        },
        { name: "project_id", label: "Which source?", type: "project" },
        {
          name: "occurred_on",
          label: "Date received",
          type: "date",
          value: today,
        },
      ],
    },
    expense: {
      title: "Add expense",
      hint: "Mark it non-essential honestly the financial-discipline score depends on that distinction.",
      submit: "Record expense",
      fields: [
        {
          name: "amount",
          label: "Amount",
          type: "number",
          required: true,
          step: "any",
          autofocus: true,
        },
        {
          name: "category",
          label: "Category",
          type: "select",
          options: [
            "food",
            "transport",
            "utilities",
            "rent",
            "data",
            "health",
            "school",
            "business",
            "other",
          ],
        },
        {
          name: "is_essential",
          label: "Essential?",
          type: "select",
          options: [
            ["1", "Essential"],
            ["0", "Non-essential"],
          ],
        },
        { name: "description", label: "Note", type: "text" },
        { name: "project_id", label: "Against a project?", type: "project" },
        { name: "occurred_on", label: "Date", type: "date", value: today },
      ],
    },
    "debt-payment": {
      title: "Record a debt payment",
      hint: "Also recorded as money leaving the household, so the runway reflects it.",
      submit: "Record payment",
      fields: [
        {
          name: "amount",
          label: "Amount",
          type: "number",
          required: true,
          step: "any",
          autofocus: true,
        },
        { name: "occurred_on", label: "Date paid", type: "date", value: today },
        { name: "note", label: "Note", type: "text" },
      ],
    },
    task: {
      title: "Add a task",
      hint: "Rank 1–3 to put it in today's Top 3 those count double toward execution.",
      submit: "Add task",
      fields: [
        {
          name: "title",
          label: "Task",
          type: "text",
          required: true,
          autofocus: true,
        },
        {
          name: "priority_rank",
          label: "Priority slot",
          type: "select",
          options: [
            ["", "Not ranked"],
            ["1", "Priority 01"],
            ["2", "Priority 02"],
            ["3", "Priority 03"],
          ],
        },
        {
          name: "estimated_minutes",
          label: "Estimate (minutes)",
          type: "number",
          placeholder: "90",
        },
        {
          name: "expected_outcome",
          label: "Expected outcome",
          type: "text",
          placeholder: "5+ replies",
        },
        { name: "for_date", label: "For", type: "date", value: today },
      ],
    },
    prospect: {
      title: "Add a prospect",
      hint: "Enters the pipeline today and counts toward this week's outreach.",
      submit: "Add prospect",
      fields: [
        {
          name: "name",
          label: "Name or organisation",
          type: "text",
          required: true,
          autofocus: true,
        },
        {
          name: "title",
          label: "What would the work be?",
          type: "text",
          placeholder: "Website & booking system",
        },
        {
          name: "value",
          label: "Estimated value",
          type: "number",
          step: "any",
        },
        { name: "phone", label: "Phone", type: "text" },
        { name: "email", label: "Email", type: "email" },
        {
          name: "next_action",
          label: "Next action",
          type: "text",
          placeholder: "Send intro message",
        },
      ],
    },
    project: {
      title: "Add a project",
      hint: "The description is what lets the board judge whether it is worth doing, not just how it is going. Gets a keep-or-kill review date two weeks out.",
      submit: "Add project",
      fields: [
        {
          name: "name",
          label: "Project name",
          type: "text",
          required: true,
          autofocus: true,
        },
        {
          name: "purpose",
          label: "What is it? (the board reads this to advise on it)",
          type: "textarea",
          placeholder:
            "One or two sentences: what the project is and what it is meant to achieve.",
        },
        {
          name: "status",
          label: "Status",
          type: "select",
          options: [
            ["development", "Development"],
            ["validation", "Validation"],
            ["strategic", "Strategic"],
            ["revenue", "Revenue-generating"],
            ["paused", "Paused"],
          ],
        },
        {
          name: "priority",
          label: "Priority",
          type: "select",
          options: [
            ["LOW", "LOW"],
            ["MEDIUM", "MEDIUM"],
            ["HIGH", "HIGH"],
          ],
        },
        { name: "customer", label: "Who is it for?", type: "text" },
        { name: "problem", label: "What problem does it solve?", type: "text" },
      ],
    },
    "describe-project": {
      title: "Describe a project",
      hint: "The board can rank a project by its hours and revenue without this, but it cannot say whether the project is worth doing until it knows what the project is.",
      submit: "Save description",
      fields: [
        {
          name: "project_id",
          label: "Which project?",
          type: "project",
          required: true,
          autofocus: true,
        },
        {
          name: "purpose",
          label: "What is it?",
          type: "textarea",
          placeholder:
            "What the project is and what it is meant to achieve.",
        },
        { name: "problem", label: "What problem does it solve?", type: "text" },
        { name: "customer", label: "Who is it for?", type: "text" },
      ],
    },
    "project-hours": {
      title: "Log hours",
      hint: "Hours are what projects actually cost. The allocation and at-risk figures are read from these entries.",
      submit: "Log hours",
      fields: [
        {
          name: "project_id",
          label: "Which project?",
          type: "project",
          required: true,
          autofocus: true,
        },
        {
          name: "hours",
          label: "Hours",
          type: "number",
          required: true,
          placeholder: "2.5",
          step: "any",
        },
        { name: "logged_on", label: "Date", type: "date", value: today },
        {
          name: "progress",
          label: "Progress now (%): optional",
          type: "number",
          placeholder: "65",
          step: "1",
        },
        { name: "note", label: "What did you do?", type: "text" },
      ],
    },
    "project-decision": {
      title: "Decide on a project",
      hint: "The verdict the board expects periodically. KILL takes the project out of the portfolio; PAUSE stops it claiming to be in flight.",
      submit: "Record verdict",
      fields: [
        {
          name: "project_id",
          label: "Which project?",
          type: "project",
          required: true,
          autofocus: true,
        },
        {
          name: "decision",
          label: "Verdict",
          type: "select",
          options: [
            ["continue", "CONTINUE: keep going as is"],
            ["pause", "PAUSE: stop spending hours for now"],
            ["pivot", "PIVOT: same effort, different direction"],
            ["kill", "KILL: out of the portfolio"],
          ],
        },
        { name: "note", label: "Why? (recorded against the project)", type: "text" },
      ],
    },
    decision: {
      title: "Log a decision",
      hint: "The metric and the review date are what make it checkable later. Without them there is no moment at which this can be shown to have been wrong.",
      submit: "Log decision",
      fields: [
        {
          name: "title",
          label: "What is being decided?",
          type: "text",
          required: true,
          autofocus: true,
        },
        {
          name: "status",
          label: "Where is it?",
          type: "select",
          options: [
            ["open", "Still deciding"],
            ["decided", "Already decided"],
          ],
        },
        { name: "situation", label: "Situation", type: "text" },
        {
          name: "options",
          label: "Options, one per line",
          type: "textarea",
          placeholder: "Take the retainer\nTake the one-off build\nDo neither and push outreach",
        },
        { name: "recommendation", label: "Recommendation", type: "text" },
        { name: "next_action", label: "Next action", type: "text" },
        {
          name: "metric",
          label: "How will you know it worked?",
          type: "text",
          placeholder: "Retainer invoice paid by the 5th",
        },
        {
          name: "review_days",
          label: "Review in how many days?",
          type: "number",
          value: "14",
          step: "1",
        },
      ],
    },
    "decision-review": {
      title: "Review a decision",
      hint: "What actually happened. A review with no finding is just a date change, and a reversal on evidence is the system working.",
      submit: "Record review",
      fields: [
        {
          name: "decision_id",
          label: "Which decision?",
          type: "decision",
          required: true,
          autofocus: true,
        },
        {
          name: "verdict",
          label: "Verdict",
          type: "select",
          options: [
            ["reviewed", "REVIEWED, it played out and stands"],
            ["reversed", "REVERSED, the evidence went the other way"],
            ["decided", "DECIDED, moving an open call to a decision"],
          ],
        },
        { name: "outcome", label: "What happened?", type: "textarea" },
        {
          name: "metric",
          label: "Metric to watch (only if deciding now)",
          type: "text",
        },
        {
          name: "review_days",
          label: "Next review in how many days?",
          type: "number",
          value: "14",
          step: "1",
        },
      ],
    },
    research: {
      title: "Record a finding",
      hint: "Label how you know it. A claim with no source is an assumption, whatever you would prefer to call it.",
      submit: "Record finding",
      fields: [
        {
          name: "question",
          label: "What were you trying to find out?",
          type: "text",
          required: true,
          autofocus: true,
          placeholder: "What do agencies in Kampala charge for a business website?",
        },
        {
          name: "finding",
          label: "What did you find?",
          type: "textarea",
          required: true,
        },
        {
          name: "kind",
          label: "How do you know?",
          type: "select",
          options: [
            ["fact", "FACT, verified, with a source"],
            ["estimate", "ESTIMATE, reasoned from something real"],
            ["assumption", "ASSUMPTION, believed but never checked"],
          ],
        },
        {
          name: "source",
          label: "Source (required for a fact)",
          type: "text",
          placeholder: "Quoted by two agencies, Aug 2026",
        },
        {
          name: "applies_to",
          label: "What decision does this bear on?",
          type: "text",
        },
        { name: "checked_on", label: "Checked on", type: "date", value: today },
        {
          name: "shelf_life_days",
          label: "Re-check after how many days?",
          type: "number",
          placeholder: "Leave blank for the default for this label",
          step: "1",
        },
      ],
    },
    goal: {
      title: "Add a goal",
      hint: "The horizon decides which rung of the ladder it sits on. A long-term goal set while cash is short will be marked as ahead of your position, which is the point.",
      submit: "Add goal",
      fields: [
        {
          name: "title",
          label: "What is the goal?",
          type: "text",
          required: true,
          autofocus: true,
        },
        {
          name: "horizon",
          label: "Horizon",
          type: "select",
          options: [
            ["30d", "30 days"],
            ["90d", "90 days"],
            ["1y", "1 year"],
            ["long", "Long term"],
          ],
        },
        {
          name: "scope",
          label: "Whose goal is it?",
          type: "select",
          options: [
            ["business", "Business"],
            ["household", "Household"],
            ["shared", "Shared, agreed with the household"],
          ],
        },
        {
          name: "metric",
          label: "Measured in",
          type: "select",
          options: [
            ["money", "Money"],
            ["count", "A count"],
            ["percent", "A percentage"],
            ["binary", "Done or not done"],
          ],
        },
        {
          name: "target",
          label: "Target",
          type: "number",
          placeholder: "580000",
          step: "any",
        },
        { name: "why", label: "Why does it matter?", type: "text" },
      ],
    },
    "goal-milestone": {
      title: "Add a milestone",
      hint: "A milestone needs a date and a line saying what done means. Without the second one, finishing becomes a matter of opinion.",
      submit: "Add milestone",
      fields: [
        {
          name: "goal_id",
          label: "Which goal?",
          type: "goal",
          required: true,
          autofocus: true,
        },
        { name: "title", label: "What is the milestone?", type: "text", required: true },
        {
          name: "metric",
          label: "Done when",
          type: "text",
          placeholder: "Deposit received",
        },
        { name: "target_on", label: "Target date", type: "date", value: today },
      ],
    },
    "milestone-done": {
      title: "Mark a milestone done",
      hint: "Recorded against a date, so the timeline shows when things actually landed rather than only that they did.",
      submit: "Mark done",
      fields: [
        {
          name: "milestone_id",
          label: "Which milestone?",
          type: "milestone",
          required: true,
          autofocus: true,
        },
        { name: "done_on", label: "Done on", type: "date", value: today },
      ],
    },
    commitment: {
      title: "Add a household commitment",
      hint: "What is owed to people rather than to creditors. The cadence is what makes overdue mean something concrete.",
      submit: "Add commitment",
      fields: [
        {
          name: "title",
          label: "What is the commitment?",
          type: "text",
          required: true,
          autofocus: true,
          placeholder: "A full day with the family, no work",
        },
        {
          name: "kind",
          label: "What kind?",
          type: "select",
          options: [
            ["relationship", "Relationship"],
            ["responsibility", "Responsibility"],
            ["rest", "Rest"],
            ["development", "Personal development"],
          ],
        },
        {
          name: "cadence_days",
          label: "How often, in days? (0 for a one off)",
          type: "number",
          value: "7",
          step: "1",
        },
        { name: "last_honoured_on", label: "Last happened on", type: "date" },
        { name: "note", label: "Note", type: "text" },
      ],
    },
    "commitment-done": {
      title: "Record a commitment honoured",
      hint: "This is the only thing that resets the clock on it, which is what stops the screen being decorative.",
      submit: "Record it",
      fields: [
        {
          name: "commitment_id",
          label: "Which commitment?",
          type: "commitment",
          required: true,
          autofocus: true,
        },
        { name: "honoured_on", label: "When", type: "date", value: today },
      ],
    },
    setting: {
      title: "Change a setting",
      hint: "These are thresholds, not preferences. Every screen that reads the one you change will change with it.",
      submit: "Save setting",
      fields: [
        {
          name: "key",
          label: "Which setting?",
          type: "select",
          autofocus: true,
          options: [
            ["monthly_survival_minor", "Monthly household requirement"],
            ["runway_critical_days", "Runway that counts as critical (days)"],
            ["runway_warning_days", "Runway that counts as tight (days)"],
            ["non_revenue_hours_cap", "Hours a non-earning project may consume"],
            ["followup_stale_days", "Days before a lead counts as neglected"],
            ["daily_prospect_target", "Prospects to contact per working day"],
            ["work_days_per_week", "Working days per week"],
            ["usd_ugx_rate", "USD to UGX rate"],
            ["auto_emergency_mode", "Automatic emergency mode (1 or 0)"],
            ["operator_name", "Operator name"],
            ["operator_location", "Operator location"],
          ],
        },
        {
          name: "value",
          label: "New value",
          type: "text",
          required: true,
          placeholder: "Amounts in whole units, days and counts as numbers",
        },
      ],
    },
    debt: {
      title: "Add a debt",
      hint: "Recorded in the currency you actually borrowed in. Converting it would invent precision the exchange rate does not have.",
      submit: "Add debt",
      fields: [
        {
          name: "name",
          label: "What is it?",
          type: "text",
          required: true,
          placeholder: "Personal loan",
          autofocus: true,
        },
        { name: "lender", label: "Who is it owed to?", type: "text" },
        {
          name: "amount",
          label: "Amount owed",
          type: "number",
          required: true,
          step: "any",
        },
        {
          name: "currency",
          label: "Currency",
          type: "select",
          options: [
            ["USD", "USD"],
            ["UGX", "UGX"],
          ],
        },
        {
          name: "target_payoff_on",
          label: "Target payoff (optional)",
          type: "date",
        },
        {
          name: "interest_note",
          label: "Interest / terms",
          type: "text",
          placeholder: "No interest, informal",
        },
      ],
    },
    "savings-goal": {
      title: "Add a savings goal",
      hint: "Lower priority numbers are funded first. The emergency fund always comes before anything else.",
      submit: "Add goal",
      fields: [
        {
          name: "name",
          label: "What are you saving for?",
          type: "text",
          required: true,
          placeholder: "Equipment upgrade",
          autofocus: true,
        },
        {
          name: "amount",
          label: "Target amount",
          type: "number",
          required: true,
          step: "any",
        },
        {
          name: "priority",
          label: "Priority",
          type: "select",
          options: [
            ["10", "Normal"],
            ["5", "High"],
            ["20", "Someday"],
          ],
        },
        { name: "note", label: "Note", type: "text" },
      ],
    },
    savings: {
      title: "Move money in or out of savings",
      hint: "This does not change your available cash, that is the balance you can actually reach, and it already excludes what you have set aside.",
      submit: "Record",
      fields: [
        {
          name: "amount",
          label: "Amount",
          type: "number",
          required: true,
          step: "any",
          autofocus: true,
        },
        {
          name: "direction",
          label: "Direction",
          type: "select",
          options: [
            ["in", "Deposit into savings"],
            ["out", "Withdraw from savings"],
          ],
        },
        { name: "goal_id", label: "Toward which goal?", type: "savings-goal" },
        { name: "occurred_on", label: "Date", type: "date", value: today },
        { name: "note", label: "Note", type: "text" },
      ],
    },
    deal: {
      title: "Add a deal",
      hint: "Stage sets a starting win probability, which is what expected pipeline value is calculated from. An expected close date is what ranks it by time-to-cash.",
      submit: "Add deal",
      fields: [
        {
          name: "title",
          label: "What is the work?",
          type: "text",
          required: true,
          placeholder: "School website rebuild",
          autofocus: true,
        },
        { name: "client", label: "Who is it with?", type: "text" },
        {
          name: "stage",
          label: "Stage",
          type: "select",
          options: [
            ["lead", "Lead, they replied"],
            ["prospect", "Prospect, not yet contacted"],
            ["proposal", "Proposal: quoted"],
            ["negotiation", "Negotiation: discussing terms"],
            ["won", "Won"],
          ],
        },
        { name: "value", label: "Deal value", type: "number", step: "any" },
        {
          name: "expected_close_on",
          label: "Expected close",
          type: "date",
        },
        {
          name: "next_action",
          label: "Next action",
          type: "text",
          placeholder: "Send the quote",
        },
      ],
    },
    opportunity: {
      title: "Add an opportunity",
      hint: "A rough estimate ranks better than an unrecorded hunch. Value ÷ time-to-cash ÷ effort is what decides DO, CONSIDER or IGNORE.",
      submit: "Add and rank",
      fields: [
        {
          name: "title",
          label: "What is it?",
          type: "text",
          required: true,
          placeholder: "School website opportunity",
          autofocus: true,
        },
        { name: "summary", label: "What do you know?", type: "text" },
        {
          name: "value",
          label: "Potential value",
          type: "number",
          required: true,
          step: "any",
        },
        {
          name: "time_to_cash",
          label: "Days until it could pay",
          type: "number",
          placeholder: "7",
        },
        {
          name: "effort_days",
          label: "Effort (days)",
          type: "number",
          step: "any",
          placeholder: "3",
        },
        {
          name: "probability",
          label: "Chance of landing",
          type: "select",
          options: [
            ["Medium", "Medium"],
            ["High", "High"],
            ["Low", "Low"],
          ],
        },
      ],
    },
    bill: {
      title: "Add an upcoming bill",
      hint: "Money you have committed but not yet paid. This raises today's income target and is what the runway is measured against.",
      submit: "Add bill",
      fields: [
        {
          name: "name",
          label: "What is it?",
          type: "text",
          required: true,
          placeholder: "Rent",
          autofocus: true,
        },
        {
          name: "amount",
          label: "Amount",
          type: "number",
          required: true,
          step: "any",
        },
        { name: "due_on", label: "Due on", type: "date", value: today },
        {
          name: "recurrence",
          label: "Repeats",
          type: "select",
          options: [
            ["monthly", "Every month"],
            ["weekly", "Every week"],
            ["quarterly", "Every quarter"],
            ["yearly", "Every year"],
            ["none", "One-off"],
          ],
        },
        {
          name: "is_essential",
          label: "Essential?",
          type: "select",
          options: [
            ["1", "Essential"],
            ["0", "Non-essential"],
          ],
        },
      ],
    },
    cash: {
      title: "Update cash balance",
      hint: "What you can actually reach right now. Everything downstream runway, income target, mode is anchored to this number.",
      submit: "Set balance",
      fields: [
        {
          name: "amount",
          label: "Balance",
          type: "number",
          required: true,
          step: "any",
          autofocus: true,
        },
        { name: "occurred_on", label: "As of", type: "date", value: today },
        {
          name: "note",
          label: "Note",
          type: "text",
          placeholder: "Mobile money + cash on hand",
        },
      ],
    },
  };

  const modal = $("[data-quick-modal]");
  const qForm = $("[data-quick-form]");
  const qTitle = $("[data-quick-title]");
  const qHint = $("[data-quick-hint]");
  const qHost = $("[data-quick-fields]");
  let openType = null;

  function buildField(field) {
    const wrap = document.createElement("label");
    wrap.className = "field";

    const label = document.createElement("span");
    label.className = "field__label";
    label.textContent = field.label + (field.required ? " *" : "");
    wrap.append(label);

    let control;
    if (field.type === "textarea") {
      control = document.createElement("textarea");
      control.className = "field__textarea";
      control.rows = 3;
      if (field.placeholder) control.placeholder = field.placeholder;
    } else if (
      field.type === "select" ||
      field.type === "project" ||
      field.type === "savings-goal" ||
      field.type === "decision" ||
      field.type === "goal" ||
      field.type === "milestone" ||
      field.type === "commitment"
    ) {
      control = document.createElement("select");
      control.className = "field__select";
      // A required picker must not offer "not linked" as its resting state, or
      // the placeholder reads as a legitimate answer.
      const options =
        field.type === "project"
          ? projectOptions(field.required)
          : field.type === "savings-goal"
            ? savingsGoalOptions()
            : field.type === "decision"
              ? decisionOptions()
              : field.type === "goal"
                ? goalOptions()
                : field.type === "milestone"
                  ? milestoneOptions()
                  : field.type === "commitment"
                    ? commitmentOptions()
                    : field.options || [];
      options.forEach((option) => {
        const [value, text] = Array.isArray(option) ? option : [option, option];
        const el = document.createElement("option");
        el.value = value;
        el.textContent = text;
        control.append(el);
      });
    } else {
      control = document.createElement("input");
      control.className = "field__input";
      control.type = field.type;
      if (field.placeholder) control.placeholder = field.placeholder;
      if (field.step) control.step = field.step;
      if (field.value) control.value = field.value;
    }

    // Applies to selects too: this used to sit inside the input branch, so a
    // required picker was silently optional.
    if (field.required) control.required = true;
    control.name = field.name;
    if (field.autofocus) control.dataset.autofocus = "1";
    wrap.append(control);

    return wrap;
  }

  function openQuickAdd(type) {
    const spec = FORMS[type];
    if (!spec || !modal) return;

    openType = type;
    qTitle.textContent = spec.title;
    qHint.textContent = spec.hint;
    $("[data-quick-submit]").textContent = spec.submit;
    qHost.replaceChildren(...spec.fields.map(buildField));

    modal.showModal();
    $("[data-autofocus]", qHost)?.focus();
  }

  $$("[data-quick-add]").forEach((button) => {
    button.addEventListener("click", () =>
      openQuickAdd(button.dataset.quickAdd),
    );
  });

  $("[data-quick-cancel]")?.addEventListener("click", () => modal?.close());

  qForm?.addEventListener("submit", async (event) => {
    event.preventDefault();

    const submit = $("[data-quick-submit]");
    const values = Object.fromEntries(new FormData(qForm).entries());

    submit.disabled = true;
    const original = submit.textContent;
    submit.textContent = "Saving…";

    try {
      const data = await post("api/entries/" + openType, values);
      modal.close();
      toast(data.message || "Saved.");
      // Figures on this page are now stale reload rather than guess.
      setTimeout(() => window.location.reload(), 600);
    } catch (error) {
      toast(error.message, true);
    } finally {
      submit.disabled = false;
      submit.textContent = original;
    }
  });

  /* --- Keyboard ---------------------------------------------------------- */
  document.addEventListener("keydown", (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
      event.preventDefault();
      input?.focus();
    }
  });
})();
