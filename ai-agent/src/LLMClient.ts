import "dotenv/config";
import OpenAI from "openai";

export type PatientQueryIntent =
    | "WELLBEING_FOLLOWUP"
    | "HIGH_BLOOD_PRESSURE";

export interface PatientQueryParameters {
    intent: PatientQueryIntent;

    // WELLBEING_FOLLOWUP parameters
    minimumAge: number;
    minimumIndicators: number;
    monthsSinceCheckup: number;

    // HIGH_BLOOD_PRESSURE parameters
    minimumSystolic: number;
    minimumDiastolic: number;
    highSystolic: number;
    highDiastolic: number;
}

export class LLMClient {
    private client: OpenAI;

    constructor() {
        this.client = new OpenAI();
    }

    async extractPatientQueryParameters(
        userQuery: string
    ): Promise<PatientQueryParameters> {

        const response = await this.client.responses.create({
            model: "gpt-5.6",

            input: `
Interpret the user's patient wellbeing request.

Choose exactly one intent:

1. WELLBEING_FOLLOWUP
Use this intent for requests about:
- wellbeing indicators
- age
- number of indicators
- time since last checkup
- follow-up candidates

Defaults when not specified:
- minimumAge = 65
- minimumIndicators = 2
- monthsSinceCheckup = 12

2. HIGH_BLOOD_PRESSURE
Use this intent for requests about:
- high blood pressure
- elevated blood pressure
- systolic pressure
- diastolic pressure
- blood pressure reports

Defaults when not specified:
- minimumAge = 18
- minimumSystolic = 140
- minimumDiastolic = 90
- highSystolic = 160
- highDiastolic = 100

For parameters that do not belong to the selected intent,
use their normal default values.

User query:
${userQuery}
`,

            text: {
                format: {
                    type: "json_schema",
                    name: "patient_query_parameters",
                    strict: true,

                    schema: {
                        type: "object",

                        properties: {
                            intent: {
                                type: "string",
                                enum: [
                                    "WELLBEING_FOLLOWUP",
                                    "HIGH_BLOOD_PRESSURE"
                                ]
                            },

                            minimumAge: {
                                type: "integer",
                                minimum: 18,
                                maximum: 120
                            },

                            minimumIndicators: {
                                type: "integer",
                                minimum: 1,
                                maximum: 7
                            },

                            monthsSinceCheckup: {
                                type: "integer",
                                minimum: 1,
                                maximum: 120
                            },

                            minimumSystolic: {
                                type: "integer",
                                minimum: 1,
                                maximum: 300
                            },

                            minimumDiastolic: {
                                type: "integer",
                                minimum: 1,
                                maximum: 200
                            },

                            highSystolic: {
                                type: "integer",
                                minimum: 1,
                                maximum: 300
                            },

                            highDiastolic: {
                                type: "integer",
                                minimum: 1,
                                maximum: 200
                            }
                        },

                        required: [
                            "intent",
                            "minimumAge",
                            "minimumIndicators",
                            "monthsSinceCheckup",
                            "minimumSystolic",
                            "minimumDiastolic",
                            "highSystolic",
                            "highDiastolic"
                        ],

                        additionalProperties: false
                    }
                }
            }
        });

        const parameters =
            JSON.parse(response.output_text) as PatientQueryParameters;

        return parameters;
    }
}