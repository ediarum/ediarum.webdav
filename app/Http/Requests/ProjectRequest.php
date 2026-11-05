<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $project_id = $this->route('project');
        $project = $project_id ? \App\Models\Project::find($project_id) : null;

        return [
            'name' => ['required', Rule::unique('projects')->ignore($project_id)],
            // Slugs are permanent: they name the repository folder and the platform's apps key
            // their own data by them (ediarum.platform docs/auth-contract.md, "Projects"). An
            // update may resend the current slug but never change it. Stricter than the contract's
            // [a-z0-9-]+: no leading, trailing or double hyphens, so a slug never looks like a
            // command-line option when it is used as a folder name.
            'slug' => $project
                ? ['sometimes', Rule::in([$project->slug])]
                : ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('projects')],
            'git_remote_url' => 'required',
            'git_username' => 'required',
            // A blank PAT on update means "keep the currently saved one" (same pattern as every other
            // secret field on this form) -- only required when there isn't one to fall back to yet.
            'git_access_token' => $project?->git_access_token ? 'nullable' : 'required',
            'git_branch' => 'required|string',
            'push_to_ediarum_services' => 'boolean',
            'is_index_app_source_of_truth' => 'boolean',
            'exist_base_url' => 'nullable',
            'exist_data_path' => 'nullable',
            'exist_username' => 'nullable',
            'exist_password' => 'nullable',
            'exist_password_delete' => 'nullable',
        ];
    }

    /**
     * Field names in validation messages, so they read "Git remote URL" rather than the default
     * "git remote url".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'git_remote_url' => 'Git remote URL',
            'git_username' => 'Git username',
            'git_access_token' => 'Git personal access token',
        ];
    }
}
