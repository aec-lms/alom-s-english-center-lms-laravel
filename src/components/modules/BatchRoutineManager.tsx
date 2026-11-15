import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useLms } from '../../context/LmsContext';
import { apiClient } from '../../services/apiClient';

type BatchRow = { id:number; course_id:number; course_title:string; branch_id:number|null; branch_name?:string; instructor_id:number; instructor_name:string; name:string; batch_code:string; schedule:string; room_number:string; max_capacity:number; enrolled_students_count:number; start_date:string; end_date:string; status:'upcoming'|'ongoing'|'completed' };
type RoutineRow = { id:number; batch_id:number; batch_name:string; course_title:string; branch_id:number|null; branch_name?:string; instructor_id:number; instructor_name:string; day_of_week:string; start_time:string; end_time:string; subject:string; room_number:string };
type Option = { id:number; name:string; branch_id?:number|null; role?:string };
const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

export const BatchRoutineManager: React.FC = () => {
  const { currentUser, language } = useLms();
  const isBn = language === 'bn';
  const [batches,setBatches] = useState<BatchRow[]>([]);
  const [routines,setRoutines] = useState<RoutineRow[]>([]);
  const [courses,setCourses] = useState<Option[]>([]);
  const [teachers,setTeachers] = useState<Option[]>([]);
  const [students,setStudents] = useState<Option[]>([]);
  const [branches,setBranches] = useState<Option[]>([]);
  const [tab,setTab] = useState<'batches'|'routines'|'print'>('batches');
  const [dayFilter,setDayFilter] = useState('All');
  const [loading,setLoading] = useState(false);
  const [saving,setSaving] = useState(false);
  const [error,setError] = useState<string|null>(null);
  const [notice,setNotice] = useState<string|null>(null);
  const [courseId,setCourseId] = useState('');
  const [branchId,setBranchId] = useState('');
  const [instructorId,setInstructorId] = useState('');
  const [batchName,setBatchName] = useState('');
  const [batchCode,setBatchCode] = useState('');
  const [schedule,setSchedule] = useState('');
  const [room,setRoom] = useState('');
  const [capacity,setCapacity] = useState('30');
  const [startDate,setStartDate] = useState('');
  const [endDate,setEndDate] = useState('');
  const [status,setStatus] = useState<'upcoming'|'ongoing'|'completed'>('upcoming');
  const [routineBatchId,setRoutineBatchId] = useState('');
  const [routineInstructorId,setRoutineInstructorId] = useState('');
  const [routineDay,setRoutineDay] = useState('Sunday');
  const [routineStart,setRoutineStart] = useState('10:00');
  const [routineEnd,setRoutineEnd] = useState('11:30');
  const [routineSubject,setRoutineSubject] = useState('');
  const [routineRoom,setRoutineRoom] = useState('');
  const [assignBatchId,setAssignBatchId] = useState('');
  const [assignStudentId,setAssignStudentId] = useState('');

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    const [br,rr,cr,ur,branchResult] = await Promise.all([apiClient.batches.getAll(),apiClient.batches.getRoutines(),apiClient.courses.getAll(),apiClient.users.getAll('',100),apiClient.branches.getAll()]);
    if(br.success && Array.isArray(br.data)) setBatches(br.data as BatchRow[]); else setError(br.error || 'Batch records could not be loaded.');
    if(rr.success && Array.isArray(rr.data)) setRoutines(rr.data as RoutineRow[]); else setError(current => current || rr.error || 'Routine records could not be loaded.');
    if(cr.success && Array.isArray(cr.data)) setCourses((cr.data as Array<Record<string,unknown>>).map(row=>({id:Number(row.id),name:String(row.title||''),branch_id:row.branch_id==null?null:Number(row.branch_id)})));
    else setError(current=>current||cr.error||'Courses could not be loaded.');
    if(ur.success && Array.isArray(ur.data)) {
      const rows=ur.data as Array<Record<string,unknown>>;
      setTeachers(rows.filter(row=>['teacher','assistant_teacher'].includes(String(row.role))&&Boolean(row.is_active)).map(row=>({id:Number(row.id),name:String(row.name||''),role:String(row.role),branch_id:row.branch_id==null?null:Number(row.branch_id)})));
      setStudents(rows.filter(row=>row.role==='student'&&Boolean(row.is_active)).map(row=>({id:Number(row.id),name:String(row.name||''),branch_id:row.branch_id==null?null:Number(row.branch_id)})));
    } else setError(current=>current||ur.error||'Users could not be loaded.');
    if(branchResult.success && Array.isArray(branchResult.data)) setBranches((branchResult.data as Array<Record<string,unknown>>).filter(row=>Boolean(row.is_active)).map(row=>({id:Number(row.id),name:String(row.name||'')})));
    else setError(current=>current||branchResult.error||'Branches could not be loaded.');
    setLoading(false);
  },[]);
  useEffect(()=>{void load();},[load]);
  useEffect(()=>{if(!branchId&&currentUser.role==='branch_manager'&&currentUser.branchId)setBranchId(currentUser.branchId);if(!branchId&&branches.length===1)setBranchId(String(branches[0].id));},[branchId,branches,currentUser.role,currentUser.branchId]);
  const filteredRoutines=useMemo(()=>dayFilter==='All'?routines:routines.filter(row=>row.day_of_week===dayFilter),[dayFilter,routines]);
  const act=async(action:()=>Promise<{success:boolean;error?:string}>,message:string)=>{setSaving(true);setError(null);setNotice(null);const result=await action();if(!result.success)setError(result.error||'The operation could not be completed.');else{setNotice(message);await load();}setSaving(false);};
  const createBatch=async(event:React.FormEvent)=>{event.preventDefault();await act(()=>apiClient.batches.create({course_id:Number(courseId),branch_id:branchId?Number(branchId):null,instructor_id:Number(instructorId),name:batchName.trim(),batch_code:batchCode.trim().toUpperCase(),schedule,room_number:room,max_capacity:Number(capacity),start_date:startDate,end_date:endDate,status}),'Batch created and saved to the database.');setBatchName('');setBatchCode('');};
  const createRoutine=async(event:React.FormEvent)=>{event.preventDefault();await act(()=>apiClient.batches.createRoutine({batch_id:Number(routineBatchId),instructor_id:routineInstructorId?Number(routineInstructorId):undefined,day_of_week:routineDay,start_time:routineStart,end_time:routineEnd,subject:routineSubject.trim(),room_number:routineRoom.trim()}),'Weekly routine slot saved.');setRoutineSubject('');};
  const assignStudent=async(event:React.FormEvent)=>{event.preventDefault();await act(()=>apiClient.batches.assignStudent(Number(assignBatchId),Number(assignStudentId)),'Student assigned to the selected batch.');setAssignStudentId('');};
  return <div className="space-y-6">
    <header className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-5"><div><h2 className="text-lg font-bold text-slate-900">{isBn?'ব্যাচ ও ক্লাস রুটিন':'Batch & Class Routine Management'}</h2><p className="mt-1 text-xs text-slate-500">Database-backed cohorts, student assignments and conflict-checked weekly schedules.</p></div><button onClick={()=>void load()} disabled={loading} className="rounded-xl border px-4 py-2 text-xs font-semibold">{loading?'Loading…':'Refresh'}</button></header>
    {error&&<div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</div>}{notice&&<div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{notice}</div>}
    <nav className="flex flex-wrap gap-2 border-b pb-2">{(['batches','routines','print'] as const).map(value=><button key={value} onClick={()=>setTab(value)} className={`rounded-lg px-4 py-2 text-xs font-semibold ${tab===value?'bg-blue-600 text-white':'bg-slate-100 text-slate-700'}`}>{value==='batches'?'Batches':value==='routines'?'Weekly routines':'Print timetable'}</button>)}</nav>
    {tab==='batches'&&<>
      <section className="rounded-2xl border border-slate-200 bg-white p-5"><h3 className="mb-3 font-bold">Create batch</h3><form onSubmit={createBatch} className="grid gap-3 md:grid-cols-3">
        <input required value={batchName} onChange={e=>setBatchName(e.target.value)} maxLength={160} placeholder="Batch name" className="rounded-lg border p-2 text-sm"/><input required value={batchCode} onChange={e=>setBatchCode(e.target.value)} maxLength={40} placeholder="Batch code" className="rounded-lg border p-2 text-sm"/>
        <select required value={courseId} onChange={e=>{const id=e.target.value;setCourseId(id);const course=courses.find(row=>String(row.id)===id);if(course?.branch_id)setBranchId(String(course.branch_id));}} className="rounded-lg border p-2 text-sm"><option value="">Select course</option>{courses.map(row=><option key={row.id} value={row.id}>{row.name}</option>)}</select>
        {currentUser.role!=='branch_manager'&&<select required value={branchId} onChange={e=>setBranchId(e.target.value)} className="rounded-lg border p-2 text-sm"><option value="">Select branch</option>{branches.map(row=><option key={row.id} value={row.id}>{row.name}</option>)}</select>}
        <select required value={instructorId} onChange={e=>setInstructorId(e.target.value)} className="rounded-lg border p-2 text-sm"><option value="">Select instructor</option>{teachers.filter(row=>!row.branch_id||!branchId||row.branch_id===Number(branchId)).map(row=><option key={row.id} value={row.id}>{row.name}</option>)}</select>
        <input value={schedule} onChange={e=>setSchedule(e.target.value)} maxLength={255} placeholder="Schedule e.g. Sun, Tue, Thu" className="rounded-lg border p-2 text-sm"/><input value={room} onChange={e=>setRoom(e.target.value)} maxLength={80} placeholder="Room number" className="rounded-lg border p-2 text-sm"/>
        <input required type="number" min="1" max="500" value={capacity} onChange={e=>setCapacity(e.target.value)} className="rounded-lg border p-2 text-sm"/><input required type="date" value={startDate} onChange={e=>setStartDate(e.target.value)} className="rounded-lg border p-2 text-sm"/><input required type="date" value={endDate} onChange={e=>setEndDate(e.target.value)} className="rounded-lg border p-2 text-sm"/>
        <select value={status} onChange={e=>setStatus(e.target.value as typeof status)} className="rounded-lg border p-2 text-sm"><option value="upcoming">Upcoming</option><option value="ongoing">Ongoing</option><option value="completed">Completed</option></select><button disabled={saving||!courseId||!instructorId} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Save batch</button>
      </form></section>
      <section className="rounded-2xl border border-slate-200 bg-white p-5"><h3 className="mb-3 font-bold">Assign enrolled student to batch</h3><form onSubmit={assignStudent} className="grid gap-3 md:grid-cols-3">
        <select required value={assignBatchId} onChange={e=>setAssignBatchId(e.target.value)} className="rounded-lg border p-2 text-sm"><option value="">Select batch</option>{batches.map(row=><option key={row.id} value={row.id}>{row.name} · {row.batch_code}</option>)}</select>
        <select required value={assignStudentId} onChange={e=>setAssignStudentId(e.target.value)} className="rounded-lg border p-2 text-sm"><option value="">Select student</option>{students.map(row=><option key={row.id} value={row.id}>{row.name}</option>)}</select><button disabled={saving||!assignBatchId||!assignStudentId} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Assign student</button></form><p className="mt-2 text-xs text-slate-500">Only students with an active or completed enrollment in the batch course can be assigned.</p></section>
      <section className="rounded-2xl border border-slate-200 bg-white p-5"><h3 className="mb-3 font-bold">Active batches ({batches.length})</h3><div className="overflow-x-auto"><table className="w-full min-w-[850px] text-left text-xs"><thead><tr className="border-b text-slate-500"><th className="p-2">Batch</th><th className="p-2">Course</th><th className="p-2">Instructor</th><th className="p-2">Students</th><th className="p-2">Dates</th><th className="p-2">Status</th><th className="p-2">Action</th></tr></thead><tbody>{batches.map(row=><tr key={row.id} className="border-b"><td className="p-2 font-semibold">{row.name}<div className="text-slate-500">{row.batch_code} · {row.branch_name||'Shared'}</div></td><td className="p-2">{row.course_title}</td><td className="p-2">{row.instructor_name}</td><td className="p-2">{row.enrolled_students_count} / {row.max_capacity}</td><td className="p-2">{row.start_date} – {row.end_date}</td><td className="p-2 capitalize">{row.status}</td><td className="p-2"><button disabled={saving} onClick={()=>void act(()=>apiClient.batches.deactivate(row.id),'Batch closed.')} className="rounded bg-red-50 px-2 py-1 text-red-700">Close</button></td></tr>)}{batches.length===0&&<tr><td colSpan={7} className="p-3 text-slate-500">No active batches found.</td></tr>}</tbody></table></div></section>
    </>}
    {tab==='routines'&&<>
      <section className="rounded-2xl border border-slate-200 bg-white p-5"><h3 className="mb-3 font-bold">Add weekly routine slot</h3><form onSubmit={createRoutine} className="grid gap-3 md:grid-cols-3">
        <select required value={routineBatchId} onChange={e=>setRoutineBatchId(e.target.value)} className="rounded-lg border p-2 text-sm"><option value="">Select batch</option>{batches.map(row=><option key={row.id} value={row.id}>{row.name} · {row.batch_code}</option>)}</select>
        <select value={routineInstructorId} onChange={e=>setRoutineInstructorId(e.target.value)} className="rounded-lg border p-2 text-sm"><option value="">Use batch instructor</option>{teachers.map(row=><option key={row.id} value={row.id}>{row.name}</option>)}</select><select value={routineDay} onChange={e=>setRoutineDay(e.target.value)} className="rounded-lg border p-2 text-sm">{days.map(day=><option key={day}>{day}</option>)}</select>
        <input required type="time" value={routineStart} onChange={e=>setRoutineStart(e.target.value)} className="rounded-lg border p-2 text-sm"/><input required type="time" value={routineEnd} onChange={e=>setRoutineEnd(e.target.value)} className="rounded-lg border p-2 text-sm"/><input required value={routineSubject} onChange={e=>setRoutineSubject(e.target.value)} maxLength={180} placeholder="Subject / lesson" className="rounded-lg border p-2 text-sm"/><input required value={routineRoom} onChange={e=>setRoutineRoom(e.target.value)} maxLength={80} placeholder="Room number" className="rounded-lg border p-2 text-sm"/><button disabled={saving||!routineBatchId} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Save routine slot</button>
      </form><p className="mt-2 text-xs text-slate-500">Overlapping instructor or room bookings on the same weekday are rejected by the server.</p></section>
      <section className="rounded-2xl border border-slate-200 bg-white p-5"><div className="mb-3 flex flex-wrap items-center justify-between gap-2"><h3 className="font-bold">Weekly routine</h3><select value={dayFilter} onChange={e=>setDayFilter(e.target.value)} className="rounded-lg border p-2 text-xs"><option value="All">All days</option>{days.map(day=><option key={day}>{day}</option>)}</select></div><div className="overflow-x-auto"><table className="w-full min-w-[750px] text-left text-xs"><thead><tr className="border-b text-slate-500"><th className="p-2">Day</th><th className="p-2">Time</th><th className="p-2">Batch / Course</th><th className="p-2">Subject</th><th className="p-2">Instructor</th><th className="p-2">Room</th><th className="p-2">Action</th></tr></thead><tbody>{filteredRoutines.map(row=><tr key={row.id} className="border-b"><td className="p-2">{row.day_of_week}</td><td className="p-2">{row.start_time}–{row.end_time}</td><td className="p-2">{row.batch_name}<div className="text-slate-500">{row.course_title}</div></td><td className="p-2">{row.subject}</td><td className="p-2">{row.instructor_name}</td><td className="p-2">{row.room_number}</td><td className="p-2"><button disabled={saving} onClick={()=>void act(()=>apiClient.batches.deleteRoutine(row.id),'Routine slot deleted.')} className="rounded bg-red-50 px-2 py-1 text-red-700">Delete</button></td></tr>)}{filteredRoutines.length===0&&<tr><td colSpan={7} className="p-3 text-slate-500">No routine slots found.</td></tr>}</tbody></table></div></section>
    </>}
    {tab==='print'&&<section className="rounded-2xl border border-slate-200 bg-white p-5"><div className="mb-4 flex items-center justify-between"><h3 className="font-bold">Printable weekly timetable</h3><button onClick={()=>window.print()} className="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white">Print</button></div><div className="grid gap-3 md:grid-cols-2">{days.map(day=><div key={day} className="rounded-xl border p-3"><h4 className="mb-2 font-bold">{day}</h4>{routines.filter(row=>row.day_of_week===day).map(row=><div key={row.id} className="border-t py-2 text-xs"><div className="font-semibold">{row.start_time}–{row.end_time} · {row.subject}</div><div className="text-slate-500">{row.batch_name} · {row.instructor_name} · {row.room_number}</div></div>)}{routines.every(row=>row.day_of_week!==day)&&<p className="text-xs text-slate-400">No classes</p>}</div>)}</div></section>}
  </div>;
};
