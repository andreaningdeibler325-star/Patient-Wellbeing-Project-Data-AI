import { createServer } from "node:http";
import { LLMClient } from "./LLMClient.js";

const llm = new LLMClient();

const server = createServer(async (req, res) => {
    // Allow browser requests during our local prototype
    res.setHeader("Access-Control-Allow-Origin", "*");
    res.setHeader("Access-Control-Allow-Headers", "Content-Type");
    res.setHeader("Access-Control-Allow-Methods", "POST, OPTIONS");

    if (req.method === "OPTIONS") {
        res.writeHead(204);
        return res.end();
    }

    if (req.method !== "POST" || req.url !== "/api/query") {
        res.writeHead(404);
        return res.end("Not found");
    }

    try {
        let body = "";

        for await (const chunk of req) {
            body += chunk;
        }

        const { query } = JSON.parse(body);

        if (typeof query !== "string" || !query.trim()) {
            res.writeHead(400, {
                "Content-Type": "application/json"
            });

            return res.end(JSON.stringify({
                error: "A query is required."
            }));
        }

        // 1. Natural language -> LLM -> intent + parameters
        const parameters =
            await llm.extractPatientQueryParameters(query);

        // 2. Route approved intent to approved PHP endpoint
        let phpEndpoint: string;

        switch (parameters.intent) {
            case "WELLBEING_FOLLOWUP":
                phpEndpoint =
                    "http://localhost:8000/api/patients.php";
                break;

            case "HIGH_BLOOD_PRESSURE":
                phpEndpoint =
                    "http://localhost:8000/api/high-blood-pressure.php";
                break;

            default:
                throw new Error(
                    `Unsupported intent: ${parameters.intent}`
                );
        }

        // 3. Parameters -> PHP -> SQL Server
        const phpResponse = await fetch(
            phpEndpoint,
            {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(parameters)
            }
        );

        const data = await phpResponse.json();

        if (!phpResponse.ok) {
            console.error(
                "PHP API error:",
                phpResponse.status,
                data
            );

            throw new Error("The PHP API request failed.");
        }

        // 4. Return intent, parameters, and patient data
        res.writeHead(200, {
            "Content-Type": "application/json"
        });

        res.end(JSON.stringify({
            intent: parameters.intent,
            parameters,
            data
        }));

    } catch (error) {
        console.error(error);

        res.writeHead(500, {
            "Content-Type": "application/json"
        });

        res.end(JSON.stringify({
            error: "AI query failed"
        }));
    }
});

server.listen(3001, () => {
    console.log("AI server running at http://localhost:3001");
});