<?php

declare(strict_types=1);

namespace App\Services\University;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Content
{
    public const TABLES = ['courses' => 'university_courses', 'interviews' => 'university_interviews', 'resources' => 'university_resources'];

    public function table(string $kind): string
    {
        abort_unless(isset(self::TABLES[$kind]), 404);

        return self::TABLES[$kind];
    }

    public function present(object $row): array
    {
        $data = (array) $row;
        foreach (['curriculum', 'chapters', 'transcript'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = json_decode($data[$key], true, 512, JSON_THROW_ON_ERROR);
            }
        }
        if (isset($data['curriculum'])) {
            $data['curriculum_locked'] = DB::table('university_attempts')->where('course_slug', $data['slug'])->exists();
        }

        return $data;
    }

    public function validate(string $kind, array $input, bool $publishing = false): array
    {
        $rules = [
            'title' => 'required|string|max:255', 'description' => 'present|nullable|string|max:20000',
            'position' => 'required|integer|min:0|max:100000', 'cover_id' => 'nullable|uuid',
        ];
        if ($kind === 'courses') {
            $rules += [
                'discipline' => 'required|string|max:80', 'curriculum' => 'required|array:lessons,exam',
                'curriculum.lessons' => 'present|array|max:100'.($publishing ? '|min:1' : ''),
                'curriculum.lessons.*' => 'array:id,title,sections,practice,video_id,questions',
                'curriculum.lessons.*.id' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', 'distinct', 'not_in:exam'],
                'curriculum.lessons.*.title' => 'required|string|max:255',
                'curriculum.lessons.*.sections' => 'present|array|max:100',
                'curriculum.lessons.*.sections.*' => 'array:title,text',
                'curriculum.lessons.*.sections.*.title' => 'required|string|max:255',
                'curriculum.lessons.*.sections.*.text' => 'required|string|max:100000',
                'curriculum.lessons.*.practice' => 'nullable|string|max:100000',
                'curriculum.lessons.*.video_id' => 'nullable|uuid',
            ];
            foreach (['curriculum.lessons.*.questions', 'curriculum.exam'] as $key) {
                $rules += [
                    $key => 'present|array|max:50'.($publishing ? '|min:1' : ''),
                    "$key.*" => 'array:id,text,options,correct,explanation',
                    "$key.*.id" => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'],
                    "$key.*.text" => 'required|string|max:4000',
                    "$key.*.options" => 'required|array|min:2|max:10',
                    "$key.*.options.*" => 'required|string|max:2000',
                    "$key.*.correct" => 'required|integer|min:0|max:9',
                    "$key.*.explanation" => 'required|string|max:10000',
                ];
            }
        } elseif ($kind === 'interviews') {
            $rules += [
                'speaker' => 'required|string|max:255', 'intro' => 'nullable|string|max:100000',
                'video_id' => ($publishing ? 'required' : 'nullable').'|uuid',
                'chapters' => 'present|array|max:200', 'chapters.*' => 'array:start,title',
                'chapters.*.start' => 'required|integer|min:0', 'chapters.*.title' => 'required|string|max:255',
                'transcript' => 'present|array|max:2000', 'transcript.*' => 'array:start,speaker,text',
                'transcript.*.start' => 'required|integer|min:0', 'transcript.*.speaker' => 'required|string|max:255',
                'transcript.*.text' => 'required|string|max:100000',
            ];
        } else {
            $rules += [
                'category' => ['required', Rule::in(['articles', 'media', 'books', 'tools'])],
                'author' => 'nullable|string|max:255', 'file_id' => 'nullable|uuid',
                'url' => ['nullable', 'url:https', 'max:2048'],
                'course_slug' => 'nullable|string|max:80', 'lesson_id' => 'nullable|string|max:80',
            ];
        }
        $data = Validator::make($input, $rules, [
            'required' => 'Заполните поле «:attribute».', 'present' => 'Не заполнено поле «:attribute».',
            'string' => 'В поле «:attribute» нужен текст.', 'array' => 'Некорректная структура: :attribute.',
            'integer' => 'В поле «:attribute» нужно целое число.', 'uuid' => 'Выберите загруженный файл.',
            'url' => 'Укажите действующую HTTPS-ссылку.', 'distinct' => 'Идентификаторы уроков должны различаться.',
        ], [
            'title' => 'Название', 'description' => 'Описание', 'discipline' => 'Дисциплина', 'speaker' => 'Участник',
            'curriculum.lessons.*.title' => 'Название урока', 'curriculum.lessons.*.sections.*.title' => 'Заголовок раздела',
            'curriculum.lessons.*.sections.*.text' => 'Текст раздела',
            'curriculum.lessons.*.questions.*.text' => 'Вопрос', 'curriculum.lessons.*.questions.*.explanation' => 'Объяснение ответа',
            'curriculum.exam.*.text' => 'Вопрос экзамена', 'curriculum.exam.*.explanation' => 'Объяснение ответа экзамена',
        ])->validate();
        $data['description'] ??= '';
        if ($kind === 'courses') {
            $sets = array_column($data['curriculum']['lessons'], 'questions');
            $sets[] = $data['curriculum']['exam'];
            foreach ($sets as $questions) {
                if (count(array_unique(array_column($questions, 'id'))) !== count($questions)) {
                    throw ValidationException::withMessages(['curriculum' => 'Идентификаторы вопросов внутри теста должны различаться.']);
                }
                foreach ($questions as $q) {
                    if (! isset($q['options'][$q['correct']]) || count(array_unique($q['options'])) !== count($q['options'])) {
                        throw ValidationException::withMessages(['curriculum' => 'Проверьте варианты и правильный ответ.']);
                    }
                }
            }
        }
        if ($kind === 'resources') {
            $targets = (int) ! empty($data['file_id']) + (int) ! empty($data['url']) + (int) ! empty($data['course_slug']);
            if ($targets > 1 || ($publishing && $targets !== 1)) {
                throw ValidationException::withMessages(['url' => 'Выберите одно назначение: файл, ссылка или урок.']);
            }
            if (! empty($data['course_slug'])) {
                $course = DB::table('university_courses')->where('slug', $data['course_slug'])->whereIn('status', ['published', 'archived'])->first();
                $lessons = $course ? json_decode($course->curriculum, true)['lessons'] : [];
                if (! $course || (! empty($data['lesson_id']) && ! in_array($data['lesson_id'], array_column($lessons, 'id'), true))) {
                    throw ValidationException::withMessages(['course_slug' => 'Курс или урок недоступен.']);
                }
            } elseif (! empty($data['lesson_id'])) {
                throw ValidationException::withMessages(['lesson_id' => 'Выберите курс для урока.']);
            }
        }
        $media = [];
        if (! empty($data['cover_id'])) {
            $media[$data['cover_id']] = 'image/';
        }
        if (! empty($data['video_id'])) {
            $media[$data['video_id']] = 'video/mp4';
        }
        if (! empty($data['file_id'])) {
            $media[$data['file_id']] = '';
        }
        foreach ($data['curriculum']['lessons'] ?? [] as $lesson) {
            if (! empty($lesson['video_id'])) {
                $media[$lesson['video_id']] = 'video/mp4';
            }
        }
        foreach ($media as $id => $mime) {
            $asset = DB::table('university_media')->where('id', $id)->first();
            if (! $asset || $asset->status !== 'ready' || ! str_starts_with($asset->mime, $mime)) {
                throw ValidationException::withMessages(['media' => 'Дождитесь завершения загрузки подходящего файла.']);
            }
        }
        if ($kind === 'interviews' && ! empty($data['video_id'])) {
            $duration = DB::table('university_media')->where('id', $data['video_id'])->value('duration');
            foreach (['chapters', 'transcript'] as $field) {
                $previous = -1;
                foreach ($data[$field] as $item) {
                    if ($item['start'] <= $previous || ($duration && $item['start'] >= $duration)) {
                        throw ValidationException::withMessages([$field => 'Таймкоды должны идти по возрастанию и находиться внутри видео.']);
                    }
                    $previous = $item['start'];
                }
            }
        }

        return $data;
    }

    public function encode(array $data): array
    {
        foreach (['curriculum', 'chapters', 'transcript'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = json_encode($data[$key], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
        }

        return $data;
    }
}
