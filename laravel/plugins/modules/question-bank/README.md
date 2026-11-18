# Question Bank plugin

Standalone plugin for reusable BCS, Bank Job and Admission questions.

## Features

- Separate categories: `bcs`, `bank_job`, `admission`.
- MCQ and written questions, subjects, topics, difficulty, marks, negative marks, explanations and tags.
- Create, edit, filter and soft-delete question records.
- Build papers from manually selected question IDs or a random filtered selection.
- Each generated paper stores immutable question snapshots, including the answer guide. Editing a source question never changes an existing paper.
- Separate protected answer-key view and print-ready question-paper view.
- Print pages can be saved as PDF using the browser's Print → Save as PDF action. No extra PDF Composer dependency is required.
- Admin permission: `question_bank.manage`.
- Branch managers are restricted to the active assigned branch when Branch Management is enabled.
- All plugin-owned tables use the `question_bank_` prefix. Plugin disable/uninstall preserves data.

## Installation

Install and enable **Question Bank** from Plugin Center. The plugin runs its own migrations and registers its own admin menu and permission. It does not require Exam Management, Course Management, or Branch Management.

## Tables

- `question_bank_questions`
- `question_bank_papers`
- `question_bank_paper_items`

The `question_bank_paper_items.question_snapshot` JSON is the immutable paper record; `question_id` is only a nullable source reference and intentionally has no foreign key so historical papers survive source-question deletion.

## Print security

Question-paper print routes omit correct answers and explanations. The answer-key route is protected by the same `question_bank.manage` permission as the admin Question Bank area. Do not expose either admin route to student-facing navigation.
