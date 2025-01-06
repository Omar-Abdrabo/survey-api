<?php

namespace App\Http\Controllers;

use App\Models\Survey;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use App\Models\SurveyAnswer;
use Illuminate\Http\Request;
use App\Models\SurveyQuestion;
use App\Enums\QuestionTypeEnum;
use App\Models\SurveyQuestionAnswer;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rules\Enum;
use App\Http\Resources\SurveyResource;
use App\Http\Requests\StoreSurveyRequest;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\UpdateSurveyRequest;
use App\Http\Requests\StoreSurveyAnswerRequest;

class SurveyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $surveys =  Survey::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(2);

        return SurveyResource::collection($surveys);
    }

    /**
     * Creates a new survey in the database.
     *
     * This method handles the creation of a new survey, including saving the survey data, handling image uploads,
     * and creating the associated survey questions.
     *
     * @param StoreSurveyRequest $request The validated request data for creating the survey.
     * @return SurveyResource The newly created survey resource.
     */
    public function store(StoreSurveyRequest $request)
    {
        $data = $request->validated();

        // Check if image was given and save on local file system
        if (isset($data['image'])) {
            $relativePath = $this->saveImage($data['image']);
            $data['image'] = $relativePath;
        }

        $survey = Survey::create($data);

        // Create new questions
        foreach ($data['questions'] as $question) {
            $question['survey_id'] = $survey->id;
            $this->createQuestion($question);
        }

        // dd($data);
        return new SurveyResource($survey);
    }

    /**
     * Display the specified resource.
     */
    public function show(Survey $survey, Request $request)
    {
        $user = $request->user();
        if ($user->id !== $survey->user_id) {
            return abort(403, 'Unauthorized action');
        }
        return new SurveyResource($survey);
    }

    /**
     * Updates an existing survey in the database.
     *
     * This method handles the update of a survey, including updating the survey data, handling image uploads and deletions,
     * and updating the associated survey questions.
     *
     * @param UpdateSurveyRequest $request The validated request data for updating the survey.
     * @param Survey $survey The survey model instance to be updated.
     * @return SurveyResource The updated survey resource.
     */
    public function update(UpdateSurveyRequest $request, Survey $survey)
    {
        $data = $request->validated();

        // Check if image was given and save on local file system
        if (isset($data['image'])) {
            $relativePath = $this->saveImage($data['image']);
            $data['image'] = $relativePath;

            // If there is an old image, delete it
            if ($survey->image) {
                $absolutePath = public_path($survey->image);
                File::delete($absolutePath);
            }
        }

        // Update survey in the database
        $survey->update($data);

        // Get ids as plain array of existing questions
        $existingIds = $survey->questions()->pluck('id')->toArray();
        // Get ids as plain array of new questions
        $newIds = Arr::pluck($data['questions'], 'id');
        // Find questions to delete
        $toDelete = array_diff($existingIds, $newIds);
        //Find questions to add
        $toAdd = array_diff($newIds, $existingIds);

        // Delete questions by $toDelete array
        SurveyQuestion::destroy($toDelete);

        // Create new questions
        foreach ($data['questions'] as $question) {
            if (in_array($question['id'], $toAdd)) {
                $question['survey_id'] = $survey->id;
                $this->createQuestion($question);
            }
        }

        // Update existing questions
        $questionMap = collect($data['questions'])->keyBy('id');
        foreach ($survey->questions as $question) {
            if (isset($questionMap[$question->id])) {
                $this->updateQuestion($question, $questionMap[$question->id]);
            }
        }

        return new SurveyResource($survey);
    }

    /**r
     * Remove the specified resource from storage.
     */
    public function destroy(Survey $survey, Request $request)
    {
        $user = $request->user();
        if ($user->id !== $survey->user_id) {
            return abort(403, 'Unauthorized action.');
        }

        $survey->delete();

        // If there is an old image, delete it
        if ($survey->image) {
            $absolutePath = public_path($survey->image);
            File::delete($absolutePath);
        }

        return response('', 204);
    }

    /**
     * Saves an image to the file system.
     *
     * This function takes a base64-encoded image string, decodes it, and saves the image to the 'images/' directory.
     * If the image type is not one of 'jpg', 'jpeg', 'gif', or 'png', an exception is thrown.
     * If the base64 decoding fails, an exception is thrown.
     * The function returns the relative path of the saved image file.
     *
     * @param string $image The base64-encoded image string.
     * @return string The relative path of the saved image file.
     * @throws \Exception If the image type is invalid or the base64 decoding fails.
     */
    private function saveImage($image)
    {
        // Check if image is valid base64 string
        if (preg_match('/^data:image\/(\w+);base64,/', $image, $type)) {
            // Take out the base64 encoded text without mime type
            $image = substr($image, strpos($image, ',') + 1);
            // Get file extension
            $type = strtolower($type[1]); // jpg, png, gif

            // Check if file is an image
            if (!in_array($type, ['jpg', 'jpeg', 'gif', 'png'])) {
                throw new \Exception('invalid image type');
            }
            $image = str_replace(' ', '+', $image);
            $image = base64_decode($image);

            if ($image === false) {
                throw new \Exception('base64_decode failed');
            }
        } else {
            throw new \Exception('did not match data URI with image data');
        }

        $dir = 'images/';
        $file = Str::random() . '.' . $type;
        $absolutePath = public_path($dir);
        $relativePath = $dir . $file;
        if (!File::exists($absolutePath)) {
            File::makeDirectory($absolutePath, 0755, true);
        }
        file_put_contents($relativePath, $image);

        return $relativePath;
    }

    /**
     * Creates a new survey question.
     *
     * This function takes an array of data containing the question, type, description, and data for a new survey question.
     * It validates the input data using the Validator class, and then creates a new SurveyQuestion model instance with the validated data.
     *
     * @param array $data An array of data containing the question, type, description, and data for the new survey question.
     * @return \App\Models\SurveyQuestion The newly created SurveyQuestion model instance.
     */
    private function createQuestion($data)
    {
        if (is_array($data['data'])) {
            $data['data'] = json_encode($data['data']);
        }
        $validator = Validator::make($data, [
            'question' => 'required|string',
            'type' => [
                'required',
                new Enum(QuestionTypeEnum::class)
            ],
            'description' => 'nullable|string',
            'data' => 'present',
            'survey_id' => 'exists:App\Models\Survey,id'
        ]);

        return SurveyQuestion::create($validator->validated());
    }


    /**
     * Updates an existing survey question.
     *
     * This function takes a SurveyQuestion model instance and an array of data containing the updated question, type, description, and data for the survey question.
     * It validates the input data using the Validator class, and then updates the existing SurveyQuestion model instance with the validated data.
     *
     * @param \App\Models\SurveyQuestion $question The existing SurveyQuestion model instance to be updated.
     * @param array $data An array of data containing the updated question, type, description, and data for the survey question.
     * @return bool Whether the update was successful.
     */
    private function updateQuestion(SurveyQuestion $question, $data)
    {
        if (is_array($data['data'])) {
            $data['data'] = json_encode($data['data']);
        }
        $validator = Validator::make($data, [
            'id' => 'exists:App\Models\SurveyQuestion,id',
            'question' => 'required|string',
            'type' => ['required', new Enum(QuestionTypeEnum::class)],
            'description' => 'nullable|string',
            'data' => 'present',
        ]);

        return $question->update($validator->validated());
    }

    /**
     * Retrieves a survey by its slug and checks if it is active and not expired.
     *
     * @param \App\Models\Survey $survey The survey model instance to retrieve.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the survey resource if the survey is active and not expired, or a 404 response if the survey is not active or has expired.
     */
    public function getBySlug(Survey $survey)
    {
        if (!$survey->status) {
            return response("", 404);
        }

        $currentDate = new \DateTime();
        $expireDate = new \DateTime($survey->expire_date);
        if ($currentDate > $expireDate) {
            return response("", 404);
        }

        return new SurveyResource($survey);
    }

    /**
     * Stores a new survey answer.
     *
     * This function takes a StoreSurveyAnswerRequest request and a Survey model instance. It validates the input data, creates a new SurveyAnswer record, and then creates a SurveyQuestionAnswer record for each answer in the request.
     *
     * @param \App\Http\Requests\StoreSurveyAnswerRequest $request The request containing the survey answers.
     * @param \App\Models\Survey $survey The survey model instance.
     * @return \Illuminate\Http\JsonResponse A JSON response with a 201 status code on success.
     */
    public function storeAnswer(StoreSurveyAnswerRequest $request, Survey $survey)
    {
        $validated = $request->validated();

        $surveyAnswer = SurveyAnswer::create([
            'survey_id' => $survey->id,
            'start_date' => date('Y-m-d H:i:s'),
            'end_date' => date('Y-m-d H:i:s'),
        ]);

        foreach ($validated['answers'] as $questionId => $answer) {
            $question = SurveyQuestion::where(['id' => $questionId, 'survey_id' => $survey->id])->get();
            if (!$question) {
                return response("Invalid question ID: \"$questionId\"", 400);
            }

            $data = [
                'survey_question_id' => $questionId,
                'survey_answer_id' => $surveyAnswer->id,
                'answer' => is_array($answer) ? json_encode($answer) : $answer
            ];

            $questionAnswer = SurveyQuestionAnswer::create($data);
        }

        return response("", 201);
    }
}
