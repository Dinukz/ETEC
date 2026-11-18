<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Manage\Concerns\ManagesCourses;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LessonController extends Controller
{
    use ManagesCourses;

    public function create(Course $course)
    {
        $this->ensureCanManage($course);
        $modules = $course->modules()->orderBy('sort_order')->get();

        return view('manage.lessons.create', compact('course', 'modules'));
    }

    public function store(Request $request, Course $course)
    {
        $this->ensureCanManage($course);
        $data = $this->validated($request, $course);

        $data['course_id'] = $course->id;
        $data['slug'] = $this->uniqueSlug($course, $data['title']);
        $data['sort_order'] = ($course->lessons()->max('sort_order') ?? 0) + 1;

        if ($request->hasFile('video_file')) {
            $data['video_provider'] = 'upload';
            $data['video_path'] = $request->file('video_file')->store('lessons/videos', 'public');
        }

        $lesson = Lesson::create($data);
        $this->storeMaterials($request, $lesson);

        return redirect()->route('manage.courses.show', $course)->with('success', "Lesson \"{$lesson->title}\" added.");
    }

    public function edit(Lesson $lesson)
    {
        $this->ensureCanManage($lesson->course);
        $course = $lesson->course;
        $modules = $course->modules()->orderBy('sort_order')->get();
        $lesson->load('materials');

        return view('manage.lessons.edit', compact('course', 'lesson', 'modules'));
    }

    public function update(Request $request, Lesson $lesson)
    {
        $this->ensureCanManage($lesson->course);
        $data = $this->validated($request, $lesson->course, $lesson);

        if ($request->hasFile('video_file')) {
            $data['video_provider'] = 'upload';
            $data['video_path'] = $request->file('video_file')->store('lessons/videos', 'public');
        }

        $lesson->update($data);
        $this->storeMaterials($request, $lesson);

        return redirect()->route('manage.courses.show', $lesson->course)->with('success', "Lesson \"{$lesson->title}\" updated.");
    }

    public function destroy(Lesson $lesson)
    {
        $this->ensureCanManage($lesson->course);
        $course = $lesson->course;
        $lesson->delete();

        return redirect()->route('manage.courses.show', $course)->with('success', 'Lesson deleted.');
    }

    public function destroyMaterial(LessonMaterial $material)
    {
        $this->ensureCanManage($material->lesson->course);

        if ($material->file_path) {
            Storage::disk('public')->delete($material->file_path);
        }
        $material->delete();

        return back()->with('success', 'Material removed.');
    }

    protected function validated(Request $request, Course $course, ?Lesson $lesson = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'module_id' => ['nullable', 'integer', 'exists:course_modules,id,course_id,'.$course->id],
            'content' => ['nullable', 'string'],
            'video_provider' => ['nullable', 'in:upload,youtube,vimeo,zoom'],
            'video_url' => ['nullable', 'url', 'max:255', 'required_if:video_provider,youtube,vimeo,zoom'],
            'video_file' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:512000'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'is_preview' => ['nullable', 'boolean'],
            'status' => ['required', 'in:draft,published'],
            'materials' => ['nullable', 'array', 'max:10'],
            'materials.*' => ['file', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,zip', 'max:20480'],
        ]);
    }

    protected function storeMaterials(Request $request, Lesson $lesson): void
    {
        foreach ($request->file('materials', []) as $file) {
            $path = $file->store('lessons/materials', 'public');
            $lesson->materials()->create([
                'title' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $file->getClientOriginalExtension(),
                'file_size' => $file->getSize(),
                'sort_order' => ($lesson->materials()->max('sort_order') ?? 0) + 1,
            ]);
        }
    }

    protected function uniqueSlug(Course $course, string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        for ($i = 2; $course->lessons()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
