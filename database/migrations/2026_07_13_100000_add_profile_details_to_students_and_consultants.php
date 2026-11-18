<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // NIC/passport ownership — captured on the Student Registration form.
            $table->boolean('nic_owned_by_student')->default(true)->after('nic');
            $table->string('nic_owner_name')->nullable()->after('nic_owned_by_student');
            $table->string('nic_owner_relationship')->nullable()->after('nic_owner_name');
            $table->string('no_objection_letter_path')->nullable()->after('nic_owner_relationship');

            // Course/placement descriptors.
            $table->string('language')->nullable()->after('education_level');
            $table->string('class_type')->nullable()->after('language');
            $table->string('course_type')->nullable()->after('class_type');

            // Informational only — does not affect the commission/service-group engine.
            $table->foreignId('partner_consultant_id')->nullable()->after('consultant_id')
                ->constrained('consultants')->nullOnDelete();
        });

        Schema::table('consultants', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('address');
            $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('date_of_birth');
            $table->boolean('nic_verified')->default(false)->after('nic');

            $table->string('swift_code')->nullable()->after('bank_branch');
            $table->string('account_proof_path')->nullable()->after('swift_code');

            $table->string('beneficiary_name')->nullable()->after('account_proof_path');
            $table->string('beneficiary_relationship')->nullable()->after('beneficiary_name');
            $table->string('beneficiary_nic')->nullable()->after('beneficiary_relationship');
            $table->date('beneficiary_date_of_birth')->nullable()->after('beneficiary_nic');
            $table->string('beneficiary_phone')->nullable()->after('beneficiary_date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_consultant_id');
            $table->dropColumn([
                'nic_owned_by_student', 'nic_owner_name', 'nic_owner_relationship',
                'no_objection_letter_path', 'language', 'class_type', 'course_type',
            ]);
        });

        Schema::table('consultants', function (Blueprint $table) {
            $table->dropColumn([
                'date_of_birth', 'gender', 'nic_verified', 'swift_code', 'account_proof_path',
                'beneficiary_name', 'beneficiary_relationship', 'beneficiary_nic',
                'beneficiary_date_of_birth', 'beneficiary_phone',
            ]);
        });
    }
};
