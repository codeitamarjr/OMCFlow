<?php

namespace App\Livewire\Company;

use Livewire\Component;
use App\Models\Business;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Auth;
use App\Jobs\CompanyFetchCroSubmissions;
use App\Models\Company as ModelsCompany;

class Company extends Component
{
    use WithPagination;

    public $search = '';
    public $perPage = 20;
    public $sortBy = 'next_annual_return';
    public $sortDirection = 'asc';
    public $allTags = [];
    public array $selectedTagFilters = [];
    public ?ModelsCompany $selectedCompany = null;
    public bool $showDetailsModal = false;
    public ?array $croDefinitions = null;
    public bool $showCroDefinitionsModal = false;


    public function mount()
    {
        $currentBusinessId = Auth::user()->current_business_id;

        if (session('last_business_id') !== $currentBusinessId) {
            session()->forget('selected_tag_filters');
            session()->put('last_business_id', $currentBusinessId);
        }

        $this->allTags = \App\Models\Tag::where('business_id', Auth::user()->current_business_id)
            ->get(['id', 'name']);

        $this->selectedTagFilters = session()->get('selected_tag_filters', []);
    }

    /**
     * Retrieve a paginated list of companies associated with the current business.
     * The list can be filtered by search keyword and selected tag filters, and is
     * sorted by the specified column and direction.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */

    #[Computed]
    public function companies()
    {
        return Business::find(Auth::user()->currentBusiness->id)->companies()
            ->with('croDocDefinitions')
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%' . $this->search . '%')
                        ->orWhere('custom', 'like', '%' . $this->search . '%')
                        ->orWhere('company_number', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->selectedTagFilters, function ($query) {
                $query->whereHas('tags', function ($q) {
                    $q->whereIn('tags.id', $this->selectedTagFilters);
                });
            })
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate($this->perPage);
    }

    /**
     * Sorts the companies by the specified column.
     * Toggles the sorting direction between ascending and descending
     * if the same column is selected consecutively.
     *
     * @param string $column The column to sort by.
     */
    public function sort($column)
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    /**
     * Store the selected tag filters in the session when they change.
     *
     * This will cause the companies list to be re-filtered when the selected
     * tag filters are updated.
     */
    public function updatedSelectedTagFilters()
    {
        session()->put('selected_tag_filters', $this->selectedTagFilters);
    }

    /**
     * Loads and displays the submission documents for the specified company.
     *
     * Finds the company by its ID and loads its submission documents. Dispatches a job
     * to update the company's submission documents from the CRO API. Opens the modal
     * to show the details of the company's submissions.
     *
     * @param int $id The ID of the company whose submission documents are to be shown.
     */
    public function showCompanySubmissions($id)
    {
        $this->selectedCompany = ModelsCompany::findOrFail($id)->load('submissionDocuments');

        CompanyFetchCroSubmissions::dispatch($this->selectedCompany);

        $this->selectedCompany->load('submissionDocuments');
        $this->showDetailsModal = true;
    }

    public function showCroDefinition(ModelsCompany $company)
    {
        abort_unless(
            Auth::user()->businesses()->where('business_id', $company->business_id)->exists(),
            403,
            'You do not have permission to view this company'
        );

        $this->selectedCompany = $company;

        $this->croDefinitions = $company->croDocDefinitions->all();
        $this->showCroDefinitionsModal = true;
    }

    /**
     * Toggles the completion status of a CRO document definition for the selected company.
     *
     * Finds the specified CRO document definition by its ID in the selected company's
     * list of document definitions. Flips its completion status and updates the 
     * pivot table with the new status, timestamp, and user ID if completed.
     * Reloads the company's CRO document definitions after the update.
     *
     * @param int $definitionId The ID of the CRO document definition to toggle.
     */
    public function toggleCroDocument(int $definitionId)
    {
        $company = $this->selectedCompany;

        $current = $company->croDocDefinitions
            ->first(fn($d) => $d->id === $definitionId)
            ->pivot
            ->completed;

        $new = ! $current;

        $company->croDocDefinitions()
            ->updateExistingPivot($definitionId, [
                'completed'    => $new,
                'completed_at' => $new ? now() : null,
                'completed_by' => $new ? Auth::id() : null,
            ]);

        $this->selectedCompany->load('croDocDefinitions');
    }

    public function render()
    {
        return view('livewire.company.company', [
            'companies' => $this->companies,
        ]);
    }
}
