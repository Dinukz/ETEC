<?php

namespace App\Support;

/**
 * Reads a whole exam paper in one go (2026-10-18).
 *
 * *"menn me exam papers tika dagann one"* — the owner's papers arrive as an
 * interactive HTML quiz (Digital Marketing Lesson 01, with an explanation for
 * every option and a hint per question) and as PDFs with an answer sheet.
 * Typing them in question by question is slow and error-prone, so a paper can
 * be imported from any of:
 *
 *  1. that HTML file itself — the `const questions = [...]` list inside it is
 *     read (a JavaScript list, converted to JSON here; nothing is executed);
 *  2. JSON in the same shape, or a list of {question, options, answer};
 *  3. plain text, as typed in Word or Notepad:
 *
 *       1. Question text
 *       A. first option
 *       B. second option
 *       Answer: B
 *       Hint: optional
 *       Explanation: optional
 *
 * ⚠️ A PDF CANNOT BE READ HERE. The Sinhala in these PDFs uses legacy font
 * encodings — copying the text out gives garbage, so it must come as one of
 * the formats above.
 *
 * Every question must have 2–6 different options and exactly one right answer,
 * or the whole import is refused with the question number named.
 */
class ExamPaperImport
{
    public const MAX_OPTIONS = 6;

    /**
     * @return array<int, array{question: string, options: array<int, string>, correct: int,
     *               option_explanations: array<int, ?string>, explanation: ?string, hint: ?string}>
     *
     * @throws \InvalidArgumentException with a message for the person importing
     */
    public static function parse(string $content): array
    {
        $content = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $content));

        if ($content === '') {
            throw new \InvalidArgumentException('The paper is empty.');
        }

        $raw = self::fromJson($content) ?? self::fromScript($content) ?? self::fromText($content);

        if ($raw === []) {
            throw new \InvalidArgumentException('No questions were found. Use the HTML quiz file, JSON, or the text format shown on the page.');
        }

        $questions = [];

        foreach (array_values($raw) as $i => $question) {
            $questions[] = self::normalise($question, $i + 1);
        }

        return $questions;
    }

    // ----- the three formats --------------------------------------------------

    /** @return array<int, mixed>|null */
    protected static function fromJson(string $content): ?array
    {
        if (! in_array($content[0], ['[', '{'], true)) {
            return null;
        }

        $data = json_decode($content, true);

        if (! is_array($data)) {
            return null;
        }

        return self::questionList($data);
    }

    /**
     * The quiz HTML holds `const questions = [ … ];` — a JavaScript list. It is
     * cut out by bracket matching and turned into JSON; nothing is run.
     *
     * @return array<int, mixed>|null
     */
    protected static function fromScript(string $content): ?array
    {
        if (! preg_match('/\b(?:const|let|var)\s+questions\s*=\s*\[/', $content, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = $m[0][1] + strlen($m[0][0]) - 1;
        $literal = self::matchingBracket($content, $start);

        if ($literal === null) {
            throw new \InvalidArgumentException('The questions list in that file is not complete.');
        }

        $data = json_decode(self::javascriptToJson($literal), true);

        if (! is_array($data)) {
            throw new \InvalidArgumentException('The questions list in that file could not be read.');
        }

        return self::questionList($data);
    }

    /** @return array<int, array<string, mixed>> */
    protected static function fromText(string $content): array
    {
        $questions = [];
        $current = null;
        $field = null; // what a continuation line belongs to

        $lines = preg_split('/\R/u', strip_tags($content)) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^(?:q(?:uestion)?\s*)?(\d{1,3})\s*[.)]\s*(.+)$/iu', $line, $m)) {
                if ($current !== null) {
                    $questions[] = $current;
                }

                $current = ['question' => $m[2], 'options' => [], 'answer' => null, 'hint' => null, 'explanation' => null];
                $field = 'question';

                continue;
            }

            if ($current === null) {
                continue; // a title or instructions above question 1
            }

            if (preg_match('/^([A-Fa-f])\s*[.)]\s*(.+)$/u', $line, $m)) {
                $text = $m[2];
                $correct = false;

                // "B. text *" or "B. text (correct)" marks the right option inline.
                if (preg_match('/\s*(?:\*|\(correct\)|✓)\s*$/iu', $text)) {
                    $text = (string) preg_replace('/\s*(?:\*|\(correct\)|✓)\s*$/iu', '', $text);
                    $correct = true;
                }

                $current['options'][] = ['text' => $text, 'isCorrect' => $correct];
                $field = 'option';

                continue;
            }

            if (preg_match('/^(?:answer|correct(?:\s+answer)?|නිවැරදි\s+පිළිතුර)\s*[:：\-]\s*([A-Fa-f])\b/iu', $line, $m)) {
                $current['answer'] = strtoupper($m[1]);
                $field = null;

                continue;
            }

            if (preg_match('/^(?:hint|ඉඟිය)\s*[:：]\s*(.*)$/iu', $line, $m)) {
                $current['hint'] = $m[1];
                $field = 'hint';

                continue;
            }

            if (preg_match('/^(?:explanation|පැහැදිලි\s+කිරීම)\s*[:：]\s*(.*)$/iu', $line, $m)) {
                $current['explanation'] = $m[1];
                $field = 'explanation';

                continue;
            }

            // A line that continues whatever came before it.
            match ($field) {
                'question' => $current['question'] .= ' '.$line,
                'option' => $current['options'][count($current['options']) - 1]['text'] .= ' '.$line,
                'hint' => $current['hint'] = trim($current['hint'].' '.$line),
                'explanation' => $current['explanation'] = trim($current['explanation'].' '.$line),
                default => null,
            };
        }

        if ($current !== null) {
            $questions[] = $current;
        }

        return $questions;
    }

    // ----- shaping ----------------------------------------------------------------

    /** @return array<int, mixed> */
    protected static function questionList(array $data): array
    {
        if (isset($data['questions']) && is_array($data['questions'])) {
            $data = $data['questions'];
        }

        return array_is_list($data) ? $data : [];
    }

    /**
     * @param  mixed  $question
     * @return array{question: string, options: array<int, string>, correct: int,
     *               option_explanations: array<int, ?string>, explanation: ?string, hint: ?string}
     */
    protected static function normalise($question, int $number): array
    {
        if (! is_array($question)) {
            throw new \InvalidArgumentException("Question {$number} could not be read.");
        }

        $text = self::clean($question['text'] ?? $question['question'] ?? $question['q'] ?? $question['title'] ?? null);

        if ($text === null) {
            throw new \InvalidArgumentException("Question {$number} has no text.");
        }

        $options = [];
        $explanations = [];
        $flagged = [];

        foreach (array_values((array) ($question['options'] ?? $question['answers'] ?? $question['choices'] ?? [])) as $i => $option) {
            if (is_array($option)) {
                $optionText = self::clean($option['text'] ?? $option['option'] ?? $option['label'] ?? null);
                $isCorrect = filter_var($option['isCorrect'] ?? $option['is_correct'] ?? $option['correct'] ?? false, FILTER_VALIDATE_BOOL);
                $explanation = self::clean($option['explanation'] ?? null);
            } else {
                $optionText = self::clean($option);
                $isCorrect = false;
                $explanation = null;
            }

            if ($optionText === null) {
                continue;
            }

            if ($isCorrect) {
                $flagged[] = count($options);
            }

            $options[] = $optionText;
            $explanations[] = $explanation;
        }

        if (count($options) < 2 || count($options) > self::MAX_OPTIONS) {
            throw new \InvalidArgumentException("Question {$number} needs between 2 and ".self::MAX_OPTIONS.' options.');
        }

        if (count(array_unique(array_map('mb_strtolower', $options))) !== count($options)) {
            throw new \InvalidArgumentException("Question {$number} has two options that are the same.");
        }

        // The right answer: flagged on an option, or named by letter, number or text.
        $answer = $question['answer'] ?? $question['correct_answer'] ?? $question['correct'] ?? null;

        if ($answer !== null && ! is_bool($answer)) {
            $byAnswer = self::answerIndex($answer, $options);

            if ($byAnswer === null) {
                throw new \InvalidArgumentException("Question {$number}: the answer \"{$answer}\" does not match any option.");
            }

            $flagged = array_values(array_unique([...$flagged, $byAnswer]));
        }

        if (count($flagged) !== 1) {
            throw new \InvalidArgumentException(count($flagged) === 0
                ? "Question {$number} has no correct answer marked."
                : "Question {$number} has more than one correct answer marked.");
        }

        return [
            'question' => $text,
            'options' => $options,
            'correct' => $flagged[0],
            'option_explanations' => $explanations,
            'explanation' => self::clean($question['explanation'] ?? null),
            'hint' => self::clean($question['hint'] ?? null),
        ];
    }

    /** "B", "b", 2 (1-based), or the option's own text → its index. */
    protected static function answerIndex(mixed $answer, array $options): ?int
    {
        $value = trim((string) $answer);

        if (preg_match('/^[A-Fa-f]$/', $value)) {
            $index = ord(strtoupper($value)) - ord('A');

            return $index < count($options) ? $index : null;
        }

        if (ctype_digit($value) && (int) $value >= 1 && (int) $value <= count($options)) {
            return (int) $value - 1;
        }

        foreach ($options as $i => $option) {
            if (mb_strtolower($option) === mb_strtolower($value)) {
                return $i;
            }
        }

        return null;
    }

    protected static function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $text === '' ? null : $text;
    }

    // ----- JavaScript list → JSON ---------------------------------------------------

    /** The text from $start (an opening bracket) to its matching close, or null. */
    protected static function matchingBracket(string $s, int $start): ?string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($s);

        for ($i = $start; $i < $length; $i++) {
            $c = $s[$i];

            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($c === '"' || $c === "'" || $c === '`') {
                $quote = $c;
            } elseif ($c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ']' || $c === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($s, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * Object keys get quotes, strings in ' or ` become "…", comments and
     * trailing commas go. Enough for a data list; a template string with ${…}
     * in it is refused rather than guessed at.
     */
    protected static function javascriptToJson(string $js): string
    {
        $out = '';
        $length = strlen($js);

        for ($i = 0; $i < $length; $i++) {
            $c = $js[$i];

            // Comments.
            if ($c === '/' && ($js[$i + 1] ?? '') === '/') {
                while ($i < $length && $js[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($c === '/' && ($js[$i + 1] ?? '') === '*') {
                $end = strpos($js, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            // Strings, whichever quote, re-emitted as a JSON string.
            if ($c === '"' || $c === "'" || $c === '`') {
                $value = '';

                for ($i++; $i < $length && $js[$i] !== $c; $i++) {
                    if ($js[$i] === '\\' && $i + 1 < $length) {
                        $next = $js[++$i];
                        $value .= match ($next) {
                            'n' => "\n",
                            't' => "\t",
                            'r' => "\r",
                            'u' => json_decode('"\\u'.substr($js, $i + 1, 4).'"') ?? '',
                            default => $next,
                        };

                        if ($next === 'u') {
                            $i += 4;
                        }

                        continue;
                    }

                    if ($c === '`' && $js[$i] === '$' && ($js[$i + 1] ?? '') === '{') {
                        throw new \InvalidArgumentException('The questions list uses a template with ${…} in it, which cannot be imported.');
                    }

                    $value .= $js[$i];
                }

                $out .= json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                continue;
            }

            // A bare key: an identifier followed by ':'.
            if (preg_match('/[A-Za-z_$]/', $c)) {
                $word = '';

                while ($i < $length && preg_match('/[\w$]/', $js[$i])) {
                    $word .= $js[$i++];
                }

                $rest = ltrim(substr($js, $i, 40));
                $i--;

                if (str_starts_with($rest, ':')) {
                    $out .= json_encode($word);
                } else {
                    $out .= $word; // true, false, null
                }

                continue;
            }

            // A trailing comma before } or ].
            if ($c === ',' && preg_match('/^\s*[}\]]/', substr($js, $i + 1, 40))) {
                continue;
            }

            $out .= $c;
        }

        return $out;
    }
}
