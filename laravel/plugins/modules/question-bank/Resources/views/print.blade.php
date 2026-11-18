<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $paper->title }} - {{ $answerKey ? 'Answer Key' : 'Question Paper' }}</title>
<style>
body{font-family:Arial,sans-serif;color:#111;max-width:900px;margin:32px auto;padding:0 24px;line-height:1.55}h1{text-align:center;margin-bottom:4px}.meta{text-align:center;color:#444;border-bottom:1px solid #999;padding-bottom:14px}.instructions{margin:18px 0;padding:12px;border:1px solid #bbb}.question{padding:14px 0;border-bottom:1px solid #ddd;break-inside:avoid}.qhead{display:flex;justify-content:space-between;font-weight:bold}.options{margin:8px 0 0 28px}.answer{margin-top:8px;padding:8px;background:#f3f3f3}.toolbar{text-align:right;margin-bottom:16px}@media print{body{margin:0 auto;padding:0 8mm}.toolbar{display:none}.question{break-inside:avoid}}
</style></head><body>
<div class="toolbar"><button onclick="window.print()">Print / Save as PDF</button></div>
<h1>{{ $paper->title }}</h1><div class="meta">{{ strtoupper(str_replace('_',' ',$paper->exam_category)) }} · {{ $paper->duration_minutes }} minutes · {{ $paper->total_marks }} marks · {{ $answerKey ? 'ANSWER KEY' : 'QUESTION PAPER' }}</div>
@if($paper->instructions && !$answerKey)<div class="instructions">{{ $paper->instructions }}</div>@endif
@foreach($paper->items as $item)
<div class="question"><div class="qhead"><span>Q{{ $item->position }}. {{ $item->question_snapshot['question_type']==='written' ? '[Written]' : '[MCQ]' }}</span><span>{{ $item->marks }} marks</span></div><div>{{ $item->question_snapshot['prompt'] }}</div>
@if(!$answerKey && $item->question_snapshot['question_type']==='mcq')<ol class="options" type="A">@foreach($item->question_snapshot['options'] ?? [] as $option)<li>{{ $option }}</li>@endforeach</ol>@endif
@if($answerKey)<div class="answer"><strong>Answer:</strong> {{ $item->question_snapshot['correct_answer'] ?: 'No answer guide supplied' }}@if($item->question_snapshot['explanation'])<div><strong>Explanation:</strong> {{ $item->question_snapshot['explanation'] }}</div>@endif</div>@elseif($item->question_snapshot['question_type']==='written')<div style="height:110px"></div>@endif
</div>
@endforeach
</body></html>
