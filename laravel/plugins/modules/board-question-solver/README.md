# Board Question Solver

Optional student exam-preparation module for SSC, HSC and Bangladesh education-board questions.

## Features
- Typed or pasted questions and optional JPG/PNG/WebP photo questions.
- Board, exam year, class, subject and question-type metadata.
- Full worked solutions, progressive-hint practice and concept explanation modes.
- Bangla or English responses.
- Student-owned solution history; Admin activity view requires its dedicated permission.
- Image bytes are passed to the configured AI provider in memory and are never written to public or private storage.
- Reuses the encrypted provider credential, role allowlist, atomic daily token budget and request budget from the AEC Role AI Assistant. No second API key is stored.

## Requirements
1. Install and enable `role-ai-assistant`.
2. Configure its encrypted OpenAI or Gemini API key, model and daily token/request budgets.
3. Allow the `student` role in Role AI Assistant settings.
4. Install and enable `board-question-solver`.
5. Grant `board-solver.use` to Students and `board-solver.configure` to Admins.
6. Enable the solver and configure image and per-student request limits in Admin settings.

## Learning integrity and privacy
- Question text and image content are treated as untrusted educational data, not instructions.
- If the learner identifies a live/timed exam, the system instruction requests hints and conceptual guidance rather than direct answers.
- Generated marking points are study guidance, not official board marking schemes.
- Unreadable images should be clarified instead of guessed.
- Student history is scoped to the authenticated student. Admin activity access is permission-gated.
- Prompts are withheld from the generic AI activity log; the solver's own history intentionally stores the student's question and answer for revision.
- Photo files are not persisted. Only a generic `[Image question]` marker and the generated solution are stored in the solver history.
