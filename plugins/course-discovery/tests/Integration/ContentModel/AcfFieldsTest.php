<?php

declare(strict_types=1);

namespace CourseDiscovery\Tests\Integration\ContentModel;

use CourseDiscovery\ContentModel\AcfFields;
use CourseDiscovery\ContentModel\PostTypes;
use CourseDiscovery\Tests\Integration\IntegrationTestCase;

/**
 * Integration, not unit: AcfFields::definition() wraps its labels and
 * instructions in __(), which is undefined without WordPress loaded (see
 * bootstrap-unit.php). ACF itself is still not loaded in either harness,
 * so these assert against the field group definition data itself
 * (AcfFields::definition()) rather than calling acf_add_local_field_group().
 */
final class AcfFieldsTest extends IntegrationTestCase
{
    private AcfFields $acfFields;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acfFields = new AcfFields();
    }

    public function test_the_location_rule_targets_the_course_post_type(): void
    {
        $definition = $this->acfFields->definition();

        /** @var array<int, array<int, array<string, mixed>>> $location */
        $location = $definition['location'];

        self::assertSame(PostTypes::COURSE, $location[0][0]['value']);
    }

    public function test_the_instructors_relationship_field_returns_ids_only(): void
    {
        $field = $this->fieldNamed(AcfFields::FIELD_INSTRUCTORS);

        self::assertSame('relationship', $field['type']);
        self::assertSame('id', $field['return_format']);
        self::assertSame([PostTypes::INSTRUCTOR], $field['post_type']);
    }

    public function test_the_providers_relationship_field_returns_ids_only(): void
    {
        $field = $this->fieldNamed(AcfFields::FIELD_PROVIDERS);

        self::assertSame('relationship', $field['type']);
        self::assertSame('id', $field['return_format']);
        self::assertSame([PostTypes::PROVIDER], $field['post_type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldNamed(string $name): array
    {
        $definition = $this->acfFields->definition();

        /** @var array<int, array<string, mixed>> $fields */
        $fields = $definition['fields'];

        foreach ($fields as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }

        self::fail("No field named {$name} found in AcfFields::definition().");
    }
}
