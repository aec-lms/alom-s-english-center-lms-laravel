<?php

declare(strict_types=1);

namespace Plugins\Modules\AttendanceManagement\Http\Controllers;

use App\Core\Plugins\PluginManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportController extends Controller
{
    public function index(Request $request): View
    {
        $actor=$request->user(); $this->ensureBranch($actor);
        $filters=$request->validate([
            'from'=>['nullable','date_format:Y-m-d'],
            'to'=>['nullable','date_format:Y-m-d','after_or_equal:from'],
            'batch_id'=>['nullable','integer','min:1'],
            'status'=>['nullable','in:present,absent,late,excused'],
        ]);
        $query=$this->query($actor,$filters);
        $rows=(clone $query)->select([
            'attendance_entries.attendance_date','attendance_entries.attendance_batch_mapping_id',
            'attendance_batch_mappings.display_name as batch_name',
        ])->selectRaw('COUNT(*) as total')
          ->selectRaw("SUM(CASE WHEN attendance_entries.status = 'present' THEN 1 ELSE 0 END) as present_count")
          ->selectRaw("SUM(CASE WHEN attendance_entries.status = 'absent' THEN 1 ELSE 0 END) as absent_count")
          ->selectRaw("SUM(CASE WHEN attendance_entries.status = 'late' THEN 1 ELSE 0 END) as late_count")
          ->selectRaw("SUM(CASE WHEN attendance_entries.status = 'excused' THEN 1 ELSE 0 END) as excused_count")
          ->groupBy('attendance_entries.attendance_date','attendance_entries.attendance_batch_mapping_id','attendance_batch_mappings.display_name')
          ->orderByDesc('attendance_entries.attendance_date')->paginate(30)->withQueryString();
        $totals=(clone $query)->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN attendance_entries.status = 'present' THEN 1 ELSE 0 END) as present_count")
            ->selectRaw("SUM(CASE WHEN attendance_entries.status = 'absent' THEN 1 ELSE 0 END) as absent_count")
            ->selectRaw("SUM(CASE WHEN attendance_entries.status = 'late' THEN 1 ELSE 0 END) as late_count")
            ->selectRaw("SUM(CASE WHEN attendance_entries.status = 'excused' THEN 1 ELSE 0 END) as excused_count")->first();
        $batches=DB::table('attendance_batch_mappings')->when($actor->hasRole('branch_manager'),fn($q)=>$q->where('branch_id',$actor->branch_id))->orderBy('display_name')->get();
        return view('attendance-management::report',compact('rows','totals','filters','batches'));
    }

    public function export(Request $request): StreamedResponse
    {
        $actor=$request->user(); $this->ensureBranch($actor);
        $filters=$request->validate(['from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d','after_or_equal:from'],'batch_id'=>['nullable','integer','min:1'],'status'=>['nullable','in:present,absent,late,excused']]);
        $query=$this->query($actor,$filters)->join('students','students.id','=','attendance_entries.student_id')
            ->join('users as student_user','student_user.id','=','students.user_id')
            ->leftJoin('users as marker','marker.id','=','attendance_entries.marked_by')
            ->addSelect('attendance_entries.attendance_date','attendance_batch_mappings.display_name as batch_name','students.student_code','student_user.name as student_name','attendance_entries.status','attendance_entries.source','attendance_entries.remarks','marker.name as marked_by');
        return response()->streamDownload(function() use($query){
            $out=fopen('php://output','w'); fputcsv($out,['Date','Batch','Student ID','Student','Status','Source','Remarks','Marked By']);
            $query->orderBy('attendance_entries.attendance_date')->chunk(500,function($items) use($out){foreach($items as $item) fputcsv($out,[$item->attendance_date,$item->batch_name,$item->student_code,$this->safe((string)$item->student_name),$item->status,$item->source,$this->safe((string)$item->remarks),$this->safe((string)$item->marked_by)]);});
            fclose($out);
        },'student-attendance-report-'.now()->format('Ymd-His').'.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'no-store, private']);
    }

    private function query($actor,array $filters)
    {
        return DB::table('attendance_entries')->join('attendance_batch_mappings','attendance_batch_mappings.id','=','attendance_entries.attendance_batch_mapping_id')
            ->when($actor->hasRole('branch_manager'),fn($q)=>$q->where('attendance_entries.branch_id',$actor->branch_id))
            ->when($filters['from']??null,fn($q,$v)=>$q->whereDate('attendance_entries.attendance_date','>=',$v))
            ->when($filters['to']??null,fn($q,$v)=>$q->whereDate('attendance_entries.attendance_date','<=',$v))
            ->when($filters['batch_id']??null,fn($q,$v)=>$q->where('attendance_entries.attendance_batch_mapping_id',$v))
            ->when($filters['status']??null,fn($q,$v)=>$q->where('attendance_entries.status',$v));
    }

    private function ensureBranch($actor): void
    {
        abort_unless($actor && ($actor->hasRole('admin') || $actor->hasRole('branch_manager')),403);
        if($actor->hasRole('branch_manager')) abort_unless((int)($actor->branch_id??0)>0 && app(PluginManager::class)->isEnabled('branch-management') && Schema::hasTable('branches') && DB::table('branches')->where('id',$actor->branch_id)->where('is_active',true)->exists(),403,'Active branch assignment required.');
        abort_unless(Schema::hasTable('attendance_entries') && Schema::hasTable('attendance_batch_mappings'),409,'Attendance reporting dependencies are unavailable.');
    }

    private function safe(string $value): string
    {
        return preg_match('/^[=+@\\-\\t\\r]/',$value) ? "'".$value : $value;
    }
}
