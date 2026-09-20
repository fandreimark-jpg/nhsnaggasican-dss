<?php

namespace App\Providers;

use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\DepedSubjectCatalog;
use App\Models\ExamRoleShare;
use App\Models\SubjectGroupWeight;
use App\Models\TransmutationRange;
use App\Services\GradingEngine;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // "Performance audit" pass — GradingEngine reads each section/term's
        // assessment evidence (and the grading reference tables) once per
        // instance instead of once per computeGrade() call. Any Eloquent
        // write to those tables marks every such cache stale so a grade
        // computed after the write always sees it. Writes that bypass model
        // events must call GradingEngine::invalidateEvidence() themselves —
        // see that method's docblock.
        foreach ([
            Assessment::class, AssessmentScore::class,
            SubjectGroupWeight::class, ExamRoleShare::class,
            TransmutationRange::class, DepedSubjectCatalog::class,
        ] as $model) {
            $model::saved(fn() => GradingEngine::invalidateEvidence());
            $model::deleted(fn() => GradingEngine::invalidateEvidence());
        }
    }
}
