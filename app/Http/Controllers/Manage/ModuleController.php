<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Manage\Concerns\ManagesCourses;
use App\Models\Course;
use App\Models\CourseModule;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    use ManagesCourses;

    public function store(Request $request, Course $course)
    {
        $this->ensureCanManage($course);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $course->modules()->create($data + ['sort_order' => ($course->modules()->max('sort_order') ?? 0) + 1]);

        return back()->with('success', 'Module added.');
    }

    public function update(Request $request, CourseModule $module)
    {
        $this->ensureCanManage($module->course);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $module->update($data);

        return back()->with('success', 'Module updated.');
    }

    public function destroy(CourseModule $module)
    {
        $this->ensureCanManage($module->course);
        $module->delete(); // lessons keep course_id; module_id set null by FK

        return back()->with('success', 'Module removed. Its lessons are now unassigned.');
    }
}
